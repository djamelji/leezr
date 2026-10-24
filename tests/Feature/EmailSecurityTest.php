<?php

namespace Tests\Feature;

use App\Core\Email\EmailAttachment;
use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-606 (LOT 7) — security & reliability: GET thread is side-effect-free (read-state only via the
 * explicit POST /read), attachment download is permission-gated.
 */
class EmailSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function superAdmin(): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'Root', 'last_name' => 'A', 'email' => 'root@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);

        return $u;
    }

    private function unreadThread(): EmailThread
    {
        $thread = EmailThread::create(['subject' => 'Unread', 'participant_email' => 'x@y.com', 'status' => 'open', 'folder' => 'inbox', 'last_message_at' => now(), 'unread_count' => 1, 'message_count' => 1]);
        EmailLog::create(['message_id' => 'u@y.com', 'thread_id' => $thread->id, 'direction' => 'received', 'recipient_email' => 'admin@leezr.com', 'from_email' => 'x@y.com', 'subject' => 'Unread', 'status' => 'sent', 'is_read' => false, 'template_key' => 'inbound', 'notification_class' => 'Inbound']);

        return $thread;
    }

    public function test_get_thread_does_not_mark_read(): void
    {
        $admin = $this->superAdmin();
        $thread = $this->unreadThread();

        $this->actingAs($admin, 'platform')->getJson("/api/platform/email/inbox/{$thread->id}")->assertOk();

        $this->assertSame(1, $thread->fresh()->unread_count, 'GET must not change read state');
        $this->assertFalse((bool) EmailLog::where('thread_id', $thread->id)->first()->is_read);
    }

    public function test_explicit_read_endpoint_marks_read(): void
    {
        $admin = $this->superAdmin();
        $thread = $this->unreadThread();

        $this->actingAs($admin, 'platform')->postJson("/api/platform/email/inbox/{$thread->id}/read")->assertOk();

        $this->assertSame(0, $thread->fresh()->unread_count);
    }

    public function test_attachment_download_requires_manage_email(): void
    {
        $att = EmailAttachment::create(['filename' => 'f.pdf', 'original_filename' => 'F.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'disk' => 'local', 'path' => 'email-attachments/f.pdf']);

        // A platform user without manage_email cannot download.
        $role = PlatformRole::create(['key' => 'no_email', 'name' => 'NoEmail']);
        $role->permissions()->sync(PlatformPermission::where('key', 'view_dashboard')->pluck('id')->all());
        $user = PlatformUser::create(['first_name' => 'No', 'last_name' => 'Email', 'email' => 'no@t.com', 'password' => 'P@ssw0rd!Strong']);
        $user->roles()->attach($role->id);

        $this->actingAs($user, 'platform')->get("/api/platform/email/inbox/attachments/{$att->id}/download")->assertForbidden();
    }
}
