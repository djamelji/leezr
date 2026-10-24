<?php

namespace Tests\Feature;

use App\Core\Email\EmailDraftService;
use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-605 (LOT 4) — server-side drafts: persist, resume (update same row), send (becomes a real
 * email and the draft is removed), delete. Last-write-wins on concurrent updates.
 */
class EmailDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_persist_creates_a_draft_in_the_draft_folder(): void
    {
        $res = app(EmailDraftService::class)->persist(['to' => 'a@b.com', 'subject' => 'Hi', 'body' => '<p>x</p>']);

        $this->assertNotNull($res['draft_id']);
        $log = EmailLog::find($res['draft_id']);
        $this->assertTrue((bool) $log->is_draft);
        $this->assertSame('draft', $log->thread->folder);
    }

    public function test_update_reuses_the_same_draft_row(): void
    {
        $first = app(EmailDraftService::class)->persist(['subject' => 'v1', 'body' => '<p>1</p>']);
        $second = app(EmailDraftService::class)->persist(['draft_id' => $first['draft_id'], 'subject' => 'v2', 'body' => '<p>2</p>']);

        $this->assertSame($first['draft_id'], $second['draft_id'], 'resume updates the same draft');
        $this->assertSame(1, EmailLog::where('is_draft', true)->count(), 'no duplicate draft row');
        $this->assertSame('v2', EmailLog::find($first['draft_id'])->subject);
    }

    public function test_send_transforms_draft_into_real_email_and_removes_draft(): void
    {
        Notification::fake();
        $draft = app(EmailDraftService::class)->persist(['to' => 'client@acme.com', 'subject' => 'Ready', 'body' => '<p>go</p>']);

        app(EmailDraftService::class)->send($draft['draft_id'], ['to' => 'client@acme.com', 'subject' => 'Ready', 'body' => '<p>go</p>']);

        $this->assertNull(EmailLog::find($draft['draft_id']), 'draft row removed after send');
        $this->assertSame(0, EmailLog::where('is_draft', true)->count());
        $this->assertSame(1, EmailThread::where('folder', 'sent')->count(), 'a real sent thread exists');
    }

    public function test_delete_removes_the_draft(): void
    {
        $draft = app(EmailDraftService::class)->persist(['subject' => 'Trash me', 'body' => '<p>x</p>']);
        app(EmailDraftService::class)->delete($draft['draft_id']);

        $this->assertSame(0, EmailLog::where('is_draft', true)->count());
    }
}
