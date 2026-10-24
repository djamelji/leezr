<?php

namespace Tests\Feature;

use App\Core\Email\EmailAttachment;
use App\Core\Email\EmailLog;
use App\Core\Email\EmailService;
use App\Notifications\Email\ManualEmailNotification;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-603 (LOT 2) — outbound reliability: attachments really sent, idempotent send, retry that
 * actually re-dispatches, server-side recipient validation, honest "queued" status.
 */
class EmailSendReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function superAdmin(): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'Root', 'last_name' => 'A', 'email' => 'root@test.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);

        return $u;
    }

    public function test_attachments_are_actually_attached_to_the_mail(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('email-attachments/2026/10/file.pdf', '%PDF-1.4 fake');
        $att = EmailAttachment::create([
            'filename' => 'file.pdf', 'original_filename' => 'Contract.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 13, 'disk' => 'local', 'path' => 'email-attachments/2026/10/file.pdf',
        ]);

        $notification = new ManualEmailNotification('Subject', '<p>Body</p>');
        $notification->attachmentIds = [$att->id];

        $mail = $notification->toMail(new AnonymousNotifiable());

        $this->assertCount(1, $mail->rawAttachments, 'the file must be attached to the outgoing mail');
        $this->assertSame('Contract.pdf', $mail->rawAttachments[0]['name']);
    }

    public function test_same_idempotency_key_sends_only_once(): void
    {
        $admin = $this->superAdmin();
        $payload = ['idempotency_key' => 'attempt-key-0001', 'to' => 'client@acme.com', 'subject' => 'Hi', 'body' => 'Hello there'];

        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', $payload)
            ->assertOk()->assertJsonPath('message', 'Email queued.');

        // Same attempt replayed (same key) → idempotent replay, NO second send.
        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', $payload)
            ->assertOk()->assertJsonPath('idempotent_replay', true);

        $this->assertSame(1, EmailLog::where('direction', 'sent')->where('subject', 'Hi')->count());
    }

    public function test_two_voluntary_identical_sends_with_different_keys_both_go_through(): void
    {
        $admin = $this->superAdmin();
        $base = ['to' => 'client@acme.com', 'subject' => 'Same subject', 'body' => 'Same body', 'cc' => 'x@a.com'];

        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', array_merge($base, ['idempotency_key' => 'k-A-11111111']))->assertOk();
        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', array_merge($base, ['idempotency_key' => 'k-B-22222222']))->assertOk();

        // Identical content + cc, but two distinct attempts → two sends (no content-collision).
        $this->assertSame(2, EmailLog::where('direction', 'sent')->where('subject', 'Same subject')->count());
    }

    public function test_compose_requires_idempotency_key_and_validates_cc(): void
    {
        $admin = $this->superAdmin();
        // Missing key → 422.
        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', [
            'to' => 'ok@acme.com', 'subject' => 'S', 'body' => 'B',
        ])->assertStatus(422);
        // Invalid cc → 422.
        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', [
            'idempotency_key' => 'kkkkkkkk', 'to' => 'ok@acme.com', 'subject' => 'S', 'body' => 'B', 'cc' => 'valid@a.com, NOT-an-email',
        ])->assertStatus(422);
    }

    public function test_retry_actually_redispatches(): void
    {
        Notification::fake();

        $failed = EmailLog::create([
            'message_id' => 'f@leezr.com', 'recipient_email' => 'dest@acme.com', 'from_email' => 'admin@leezr.com',
            'subject' => 'Failed one', 'body_html' => '<p>x</p>', 'template_key' => 'manual.compose',
            'notification_class' => 'ManualEmailNotification', 'status' => 'failed', 'direction' => 'sent',
        ]);

        $newLog = app(EmailService::class)->retry($failed);

        $this->assertSame('queued', $newLog->status);
        Notification::assertSentOnDemand(ManualEmailNotification::class);
    }

    public function test_retry_reassociates_attachments_to_the_new_send(): void
    {
        Notification::fake();

        $failed = EmailLog::create([
            'message_id' => 'fa@leezr.com', 'recipient_email' => 'd@acme.com', 'from_email' => 'admin@leezr.com',
            'subject' => 'With file', 'body_html' => '<p>x</p>', 'template_key' => 'manual.compose',
            'notification_class' => 'ManualEmailNotification', 'status' => 'failed', 'direction' => 'sent',
        ]);
        EmailAttachment::create([
            'email_log_id' => $failed->id, 'filename' => 'a.pdf', 'original_filename' => 'A.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'disk' => 'local', 'path' => 'email-attachments/a.pdf',
        ]);

        $newLog = app(EmailService::class)->retry($failed);

        // A fresh attachment row is linked to the NEW send (original preserved), same file path.
        $copies = EmailAttachment::where('email_log_id', $newLog->id)->get();
        $this->assertCount(1, $copies);
        $this->assertSame('email-attachments/a.pdf', $copies[0]->path);
        $this->assertSame(1, EmailAttachment::where('email_log_id', $failed->id)->count(), 'original attachment preserved');
    }

    public function test_retry_refuses_non_failed(): void
    {
        $sent = EmailLog::create([
            'message_id' => 's@leezr.com', 'recipient_email' => 'd@a.com', 'from_email' => 'admin@leezr.com',
            'subject' => 'Sent', 'template_key' => 'manual.compose', 'notification_class' => 'X', 'status' => 'sent', 'direction' => 'sent',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        app(EmailService::class)->retry($sent);
    }
}
