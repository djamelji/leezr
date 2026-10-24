<?php

namespace Tests\Feature;

use App\Core\Email\EmailThread;
use App\Core\Email\ImapFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ADR-602 (LOT 1) — IMAP threading must not collapse independent same-sender/same-subject
 * emails into one thread (the 1300+-message spam collapse). The participant+subject fallback now
 * applies ONLY to replies/forwards within a recent window.
 */
class ImapThreadingTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(string $from, string $subject, ?string $inReplyTo = null, ?string $refs = null, string $folder = 'inbox'): EmailThread
    {
        $m = new ReflectionMethod(ImapFetcher::class, 'findOrCreateThread');
        $m->setAccessible(true);

        return $m->invoke(new ImapFetcher(), $from, 'Sender', $subject, $inReplyTo, $refs, $folder);
    }

    public function test_independent_same_sender_same_subject_emails_do_not_collapse(): void
    {
        // Two fresh (non-reply) emails, identical sender + subject → MUST be two distinct threads.
        $t1 = $this->resolve('spam@ezweb.ne.jp', 'Delivery notification');
        $t2 = $this->resolve('spam@ezweb.ne.jp', 'Delivery notification');

        $this->assertNotSame($t1->id, $t2->id, 'independent same-subject blasts must not merge');
        $this->assertSame(2, EmailThread::count());
    }

    public function test_real_reply_attaches_to_existing_thread(): void
    {
        $original = $this->resolve('client@acme.com', 'Project kickoff');
        // A reply (Re:) from the same participant within the window → same thread.
        $reply = $this->resolve('client@acme.com', 'Re: Project kickoff');

        $this->assertSame($original->id, $reply->id, 'genuine reply should thread together');
        $this->assertSame(1, EmailThread::count());
    }

    public function test_reply_matches_via_references_message_id(): void
    {
        // Build an original thread + log, then a reply referencing its Message-ID.
        $thread = EmailThread::create(['subject' => 'Ticket', 'participant_email' => 'x@y.com', 'status' => 'open', 'last_message_at' => now()]);
        \App\Core\Email\EmailLog::create(['message_id' => 'root@y.com', 'thread_id' => $thread->id, 'direction' => 'received', 'recipient_email' => 'admin@leezr.com', 'from_email' => 'x@y.com', 'subject' => 'Ticket', 'status' => 'sent', 'template_key' => 'inbound', 'notification_class' => 'Inbound']);

        $resolved = $this->resolve('x@y.com', 'Totally different subject', 'root@y.com');
        $this->assertSame($thread->id, $resolved->id, 'In-Reply-To must win regardless of subject');
    }
}
