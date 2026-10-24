<?php

namespace Tests\Feature;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-602 (LOT 1) — inbound webhook is fail-closed: disabled without a configured secret,
 * secret-verified when enabled, and idempotent on Message-ID replay (never a 500). IMAP reception
 * is a separate channel and is unaffected.
 */
class EmailInboundSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'from' => 'ext@example.com',
            'from_name' => 'Ext Sender',
            'to' => 'admin@leezr.com',
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
            'message_id' => 'msg-1@example.com',
        ], $override);
    }

    public function test_endpoint_is_disabled_without_configured_secret(): void
    {
        config()->set('services.email_inbound.secret', '');
        $this->postJson('/api/platform/email/inbound', $this->payload())->assertNotFound();
        $this->assertSame(0, EmailLog::where('direction', 'received')->count());
    }

    public function test_rejects_wrong_secret(): void
    {
        config()->set('services.email_inbound.secret', 'right-secret');
        $this->postJson('/api/platform/email/inbound', $this->payload(), ['X-Inbound-Secret' => 'wrong'])
            ->assertStatus(401);
        $this->assertSame(0, EmailLog::where('direction', 'received')->count());
    }

    public function test_accepts_and_persists_with_valid_secret(): void
    {
        config()->set('services.email_inbound.secret', 'right-secret');
        $this->postJson('/api/platform/email/inbound', $this->payload(), ['X-Inbound-Secret' => 'right-secret'])
            ->assertOk();

        $log = EmailLog::where('message_id', 'msg-1@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame('received', $log->direction);
        $this->assertNotNull($log->thread_id);
    }

    public function test_is_idempotent_on_duplicate_message_id(): void
    {
        config()->set('services.email_inbound.secret', 's');
        $h = ['X-Inbound-Secret' => 's'];
        $this->postJson('/api/platform/email/inbound', $this->payload(), $h)->assertOk();
        // Re-delivery of the same Message-ID → no-op (NOT a 500 unique-constraint crash).
        $this->postJson('/api/platform/email/inbound', $this->payload(['subject' => 'Hello again']), $h)
            ->assertOk()
            ->assertJsonPath('message', 'Duplicate ignored.');

        $this->assertSame(1, EmailLog::where('message_id', 'msg-1@example.com')->count());
    }

    public function test_reply_is_threaded_via_in_reply_to(): void
    {
        config()->set('services.email_inbound.secret', 's');
        $h = ['X-Inbound-Secret' => 's'];

        // Seed an existing thread + original message.
        $thread = EmailThread::create(['subject' => 'Topic', 'participant_email' => 'a@b.com', 'status' => 'open', 'last_message_at' => now()]);
        EmailLog::create(['message_id' => 'orig@b.com', 'thread_id' => $thread->id, 'direction' => 'sent', 'recipient_email' => 'a@b.com', 'from_email' => 'admin@leezr.com', 'subject' => 'Topic', 'status' => 'sent', 'template_key' => 'manual', 'notification_class' => 'Manual']);

        $this->postJson('/api/platform/email/inbound', $this->payload([
            'message_id' => 'reply@b.com', 'in_reply_to' => '<orig@b.com>', 'subject' => 'Re: Topic',
        ]), $h)->assertOk()->assertJsonPath('thread_id', $thread->id);

        $this->assertSame($thread->id, EmailLog::where('message_id', 'reply@b.com')->first()->thread_id);
    }
}
