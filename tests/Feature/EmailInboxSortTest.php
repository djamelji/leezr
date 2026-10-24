<?php

namespace Tests\Feature;

use App\Core\Email\EmailThread;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-607 (LOT 5) — server-side sort of the inbox list. */
class EmailInboxSortTest extends TestCase
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

    private function seedThreads(): void
    {
        EmailThread::create(['subject' => 'Old', 'participant_email' => 'a@y.com', 'status' => 'open', 'folder' => 'inbox', 'last_message_at' => now()->subDays(3), 'unread_count' => 0, 'message_count' => 1]);
        EmailThread::create(['subject' => 'New', 'participant_email' => 'b@y.com', 'status' => 'open', 'folder' => 'inbox', 'last_message_at' => now(), 'unread_count' => 0, 'message_count' => 1]);
        EmailThread::create(['subject' => 'Unread', 'participant_email' => 'c@y.com', 'status' => 'open', 'folder' => 'inbox', 'last_message_at' => now()->subDay(), 'unread_count' => 5, 'message_count' => 1]);
    }

    public function test_default_sort_is_newest_first(): void
    {
        $this->seedThreads();
        $data = $this->actingAs($this->admin(), 'platform')->getJson('/api/platform/email/inbox')->json('data');
        $this->assertSame('New', $data[0]['subject']);
        $this->assertSame('Old', $data[2]['subject']);
    }

    public function test_date_asc_sort(): void
    {
        $this->seedThreads();
        $data = $this->actingAs($this->admin(), 'platform')->getJson('/api/platform/email/inbox?sort=date_asc')->json('data');
        $this->assertSame('Old', $data[0]['subject']);
        $this->assertSame('New', $data[2]['subject']);
    }

    public function test_unread_first_sort(): void
    {
        $this->seedThreads();
        $data = $this->actingAs($this->admin(), 'platform')->getJson('/api/platform/email/inbox?sort=unread_first')->json('data');
        $this->assertSame('Unread', $data[0]['subject']);
    }
}
