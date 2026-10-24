<?php

namespace Tests\Feature;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-610 (LOT 1) — authoritative unread MESSAGE count (received + unread + inbox folder). */
class EmailUnreadCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function admin(): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'R', 'last_name' => 'A', 'email' => 'r@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);

        return $u;
    }

    private function thread(string $folder): EmailThread
    {
        return EmailThread::create(['subject' => 'T', 'participant_email' => 'x@y.com', 'status' => 'open', 'folder' => $folder, 'last_message_at' => now(), 'unread_count' => 0, 'message_count' => 0]);
    }

    private function msg(EmailThread $t, string $direction, bool $read): void
    {
        EmailLog::create(['message_id' => uniqid().'@y.com', 'thread_id' => $t->id, 'direction' => $direction, 'recipient_email' => 'admin@leezr.com', 'from_email' => 'x@y.com', 'subject' => 'T', 'status' => 'sent', 'is_read' => $read, 'template_key' => 'inbound', 'notification_class' => 'Inbound']);
    }

    public function test_counts_only_received_unread_inbox_messages(): void
    {
        $inbox = $this->thread('inbox');
        $this->msg($inbox, 'received', false); // ✓ counts
        $this->msg($inbox, 'received', false); // ✓ counts (messages, not threads → 2)
        $this->msg($inbox, 'received', true);  // ✗ already read
        $this->msg($inbox, 'sent', false);     // ✗ sent

        $this->msg($this->thread('spam'), 'received', false);  // ✗ spam
        $this->msg($this->thread('trash'), 'received', false); // ✗ trash

        $count = $this->actingAs($this->admin(), 'platform')
            ->getJson('/api/platform/email/inbox/unread-count')->assertOk()->json('count');

        $this->assertSame(2, $count);
    }

    public function test_requires_manage_email_permission(): void
    {
        $role = PlatformRole::create(['key' => 'no_email', 'name' => 'NoEmail']);
        $role->permissions()->sync(PlatformPermission::where('key', 'view_dashboard')->pluck('id')->all());
        $user = PlatformUser::create(['first_name' => 'N', 'last_name' => 'E', 'email' => 'n@t.com', 'password' => 'P@ssw0rd!Strong']);
        $user->roles()->attach($role->id);

        $this->actingAs($user, 'platform')
            ->getJson('/api/platform/email/inbox/unread-count')->assertForbidden();
    }
}
