<?php

namespace Tests\Feature;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-610 (LOT 2) — the actions that change the unread count (read, spam, delete, move) publish the
 * existing `email.updated` realtime event, which is what drives the live badge refresh. We assert
 * event EMISSION (deterministic); live SSE browser delivery is not asserted here.
 */
class EmailRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    private object $fakePublisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->fakePublisher = new class implements RealtimePublisher
        {
            public array $topics = [];

            public function publish(EventEnvelope $envelope): void
            {
                $this->topics[] = $envelope->topic;
            }
        };
        $this->app->instance(RealtimePublisher::class, $this->fakePublisher);
    }

    private function admin(): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'R', 'last_name' => 'A', 'email' => 'r@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);

        return $u;
    }

    private function inboxThread(): EmailThread
    {
        $t = EmailThread::create(['subject' => 'T', 'participant_email' => 'x@y.com', 'status' => 'open', 'folder' => 'inbox', 'last_message_at' => now(), 'unread_count' => 1, 'message_count' => 1]);
        EmailLog::create(['message_id' => 'm@y.com', 'thread_id' => $t->id, 'direction' => 'received', 'recipient_email' => 'admin@leezr.com', 'from_email' => 'x@y.com', 'subject' => 'T', 'status' => 'sent', 'is_read' => false, 'template_key' => 'inbound', 'notification_class' => 'Inbound']);

        return $t;
    }

    public function test_mark_read_publishes_email_updated(): void
    {
        $t = $this->inboxThread();
        $this->actingAs($this->admin(), 'platform')->postJson("/api/platform/email/inbox/{$t->id}/read")->assertOk();
        $this->assertContains('email.updated', $this->fakePublisher->topics);
    }

    public function test_bulk_spam_publishes_email_updated(): void
    {
        $t = $this->inboxThread();
        $this->actingAs($this->admin(), 'platform')
            ->postJson('/api/platform/email/inbox/bulk', ['ids' => [$t->id], 'action' => 'spam'])->assertOk();
        $this->assertContains('email.updated', $this->fakePublisher->topics);
    }

    public function test_bulk_delete_publishes_email_updated(): void
    {
        $t = $this->inboxThread();
        $this->actingAs($this->admin(), 'platform')
            ->postJson('/api/platform/email/inbox/bulk', ['ids' => [$t->id], 'action' => 'delete'])->assertOk();
        $this->assertContains('email.updated', $this->fakePublisher->topics);
    }
}
