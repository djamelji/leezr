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

    public function test_compose_is_idempotent_on_duplicate_submit(): void
    {
        $admin = $this->superAdmin();
        $payload = ['to' => 'client@acme.com', 'subject' => 'Hi', 'body' => 'Hello there'];

        $first = $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', $payload);
        $first->assertOk()->assertJsonPath('message', 'Email queued.');

        $second = $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', $payload);
        $second->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, EmailLog::where('direction', 'sent')->where('subject', 'Hi')->count(), 'only one email sent');
    }

    public function test_compose_rejects_invalid_cc(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'platform')->postJson('/api/platform/email/inbox/compose', [
            'to' => 'ok@acme.com', 'subject' => 'S', 'body' => 'B', 'cc' => 'valid@a.com, NOT-an-email',
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
