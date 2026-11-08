<?php

namespace Tests\Feature;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use App\Core\Email\ImapFetcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-740 — IMAP import idempotence, encoding safety and atomic thread+log creation.
 *
 * Reproduces and guards against the production thread explosion (22 091 threads from
 * ~14 real messages) caused by: dedup-only-on-Message-ID, orphan threads on a failing
 * log insert, and random UUIDs for messages without a Message-ID.
 */
class ImapIdempotenceTest extends TestCase
{
    use RefreshDatabase;

    private function msg(array $over = []): array
    {
        return array_merge([
            'account' => 'admin@leezr.com',
            'uidvalidity' => 1000,
            'uid' => 42,
            'imap_folder' => 'INBOX',
            'app_folder' => 'inbox',
            'message_id' => 'abc@google.com',
            'in_reply_to' => null,
            'references' => null,
            'from_email' => 'noreply-dmarc-support@google.com',
            'from_name' => 'DMARC',
            'to_email' => 'admin@leezr.com',
            'subject' => 'Report Domain: leezr.com',
            'body_html' => null,
            'body_text' => 'hello',
            'date' => now(),
        ], $over);
    }

    public function test_same_message_imported_twice_creates_single_record(): void
    {
        $f = new ImapFetcher();

        $first = $f->storeMessage($this->msg());
        $second = $f->storeMessage($this->msg());

        $this->assertNotNull($first);
        $this->assertNull($second, 'second import of the same IMAP identity must be skipped');
        $this->assertSame(1, EmailLog::count());
        $this->assertSame(1, EmailThread::count());
    }

    public function test_message_without_message_id_no_duplication(): void
    {
        $f = new ImapFetcher();

        $a = $f->storeMessage($this->msg(['message_id' => null, 'uid' => 7]));
        $b = $f->storeMessage($this->msg(['message_id' => null, 'uid' => 7]));

        $this->assertNotNull($a);
        $this->assertNull($b);
        $this->assertSame(1, EmailLog::count());
        // Stored id is DETERMINISTIC (derived from the IMAP identity), never a random UUID.
        $this->assertStringStartsWith('imap-', $a->message_id);
        $this->assertStringEndsWith('@imap.inbound', $a->message_id);
    }

    public function test_same_message_id_in_two_accounts_stays_isolated(): void
    {
        $f = new ImapFetcher();

        $a = $f->storeMessage($this->msg(['account' => 'admin@leezr.com', 'uid' => 1, 'message_id' => 'shared@x.com']));
        $b = $f->storeMessage($this->msg(['account' => 'support@leezr.com', 'uid' => 1, 'message_id' => 'shared@x.com']));

        $this->assertNotNull($a);
        $this->assertNotNull($b, 'the same Message-ID in a different account must import');
        $this->assertSame(2, EmailLog::count());
    }

    public function test_non_utf8_subject_and_body_import_successfully(): void
    {
        $f = new ImapFetcher();

        // Raw Shift-JIS bytes (日本語 — invalid as UTF-8) that previously caused SQLSTATE 1366.
        $sjisSubject = mb_convert_encoding('配信通知テスト', 'SJIS-win', 'UTF-8');
        $sjisBody = mb_convert_encoding('本文のテスト内容', 'SJIS-win', 'UTF-8');
        $this->assertFalse(mb_check_encoding($sjisSubject, 'UTF-8'), 'precondition: subject is not valid UTF-8');

        $log = $f->storeMessage($this->msg([
            'uid' => 99,
            'message_id' => null,
            'from_email' => 'no-reply@ezweb.ne.jp',
            'subject' => $sjisSubject,
            'body_text' => $sjisBody,
            'body_html' => null,
        ]));

        $this->assertNotNull($log, 'non-UTF-8 message must import successfully');
        $this->assertTrue(mb_check_encoding($log->subject, 'UTF-8'), 'stored subject must be valid UTF-8');
        $this->assertTrue(mb_check_encoding($log->body_text, 'UTF-8'), 'stored body must be valid UTF-8');
        $this->assertSame(1, EmailThread::count());
    }

    public function test_sql_exception_rolls_back_the_thread(): void
    {
        $f = new ImapFetcher();

        // Force the log insert to throw AFTER the thread has been created in the transaction.
        EmailLog::creating(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $f->storeMessage($this->msg(['uid' => 123]));
            $this->fail('expected the insert failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            EmailLog::flushEventListeners();
        }

        $this->assertSame(0, EmailThread::count(), 'a failing insert must leave NO orphan thread');
        $this->assertSame(0, EmailLog::count());
    }

    public function test_unique_identity_constraint_blocks_duplicate_at_db_level(): void
    {
        // The DB-level guard that protects truly concurrent imports.
        EmailThread::create(['subject' => 't', 'participant_email' => 'a@b.com', 'status' => 'open', 'last_message_at' => now()]);
        $base = [
            'imap_account' => 'admin@leezr.com', 'imap_uidvalidity' => 5, 'imap_uid' => 10, 'imap_folder' => 'INBOX',
            'recipient_email' => 'admin@leezr.com', 'from_email' => 'a@b.com', 'subject' => 't',
            'status' => 'sent', 'direction' => 'received', 'template_key' => 'inbound', 'notification_class' => 'ImapFetch',
        ];
        EmailLog::create(array_merge($base, ['message_id' => 'one@x']));

        $this->expectException(QueryException::class);
        EmailLog::create(array_merge($base, ['message_id' => 'two@x']));
    }

    public function test_uidvalidity_change_with_same_message_id_does_not_duplicate(): void
    {
        $f = new ImapFetcher();

        $a = $f->storeMessage($this->msg(['uidvalidity' => 100, 'uid' => 5, 'message_id' => 'keep@x.com']));
        // Server reset UIDVALIDITY → new uidvalidity + renumbered uid, same Message-ID.
        $b = $f->storeMessage($this->msg(['uidvalidity' => 200, 'uid' => 9, 'message_id' => 'keep@x.com']));

        $this->assertNotNull($a);
        $this->assertNull($b, 'same Message-ID in the same account must not duplicate after a UIDVALIDITY reset');
        $this->assertSame(1, EmailLog::count());
    }

    public function test_scheduler_rerun_creates_no_new_threads(): void
    {
        $f = new ImapFetcher();

        // Two genuinely distinct messages (same sender/subject — must NOT collapse, ADR-602).
        $f->storeMessage($this->msg(['uid' => 1, 'message_id' => null, 'from_email' => 'x@ezweb.ne.jp', 'subject' => 'Notice']));
        $f->storeMessage($this->msg(['uid' => 2, 'message_id' => null, 'from_email' => 'x@ezweb.ne.jp', 'subject' => 'Notice']));
        $this->assertSame(2, EmailThread::count());
        $this->assertSame(2, EmailLog::count());

        // Simulate 3 more scheduler passes over the SAME mailbox (no new messages).
        for ($pass = 0; $pass < 3; $pass++) {
            $f->storeMessage($this->msg(['uid' => 1, 'message_id' => null, 'from_email' => 'x@ezweb.ne.jp', 'subject' => 'Notice']));
            $f->storeMessage($this->msg(['uid' => 2, 'message_id' => null, 'from_email' => 'x@ezweb.ne.jp', 'subject' => 'Notice']));
        }

        $this->assertSame(2, EmailThread::count(), 'repeated scheduler passes must not multiply threads');
        $this->assertSame(2, EmailLog::count());
    }
}
