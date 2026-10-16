<?php

namespace Tests\Feature;

use App\Core\Automation\Jobs\RunScheduledTaskJob;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Audit\PlatformAuditLog;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automations cockpit — piloting security (scheduler integration).
 *
 * The "run now" control must never freely trigger mass financial tasks: sensitive
 * tasks require a dedicated permission + explicit confirmation, runs are audited, and
 * concurrent/overlapping executions are blocked.
 */
class AutomationCockpitSecurityTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->superAdmin = PlatformUser::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'ops-admin@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $this->superAdmin->roles()->attach(PlatformRole::where('key', 'super_admin')->first());
    }

    private function restrictedUser(array $permissionKeys): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'ops_limited', 'name' => 'Ops Limited']);
        $role->permissions()->sync(
            PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->toArray()
        );

        $user = PlatformUser::create([
            'first_name' => 'Limited', 'last_name' => 'Ops',
            'email' => 'limited-ops@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_exposes_sensitive_flag(): void
    {
        $res = $this->actingAs($this->superAdmin, 'platform')->getJson('/api/platform/automations');
        $res->assertStatus(200);

        $tasks = collect($res->json('tasks'))->keyBy('name');
        $this->assertTrue($tasks['billing:renew']['sensitive'], 'billing:renew must be flagged sensitive.');
        $this->assertFalse($tasks['email:fetch-inbox']['sensitive'], 'email:fetch-inbox must not be sensitive.');
    }

    public function test_sensitive_run_without_permission_is_forbidden(): void
    {
        Queue::fake();
        $user = $this->restrictedUser(['manage_automations']); // can see cockpit, cannot run sensitive

        $this->actingAs($user, 'platform')
            ->postJson('/api/platform/automations/run', ['task' => 'billing:renew', 'confirm' => true])
            ->assertStatus(403)
            ->assertJson(['code' => 'sensitive_permission_required']);

        Queue::assertNothingPushed();
    }

    public function test_sensitive_run_without_confirmation_is_rejected(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin, 'platform')
            ->postJson('/api/platform/automations/run', ['task' => 'billing:renew'])
            ->assertStatus(422)
            ->assertJson(['code' => 'confirmation_required', 'sensitive' => true]);

        Queue::assertNothingPushed();
    }

    public function test_sensitive_run_with_permission_and_confirmation_dispatches_and_audits(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin, 'platform')
            ->postJson('/api/platform/automations/run', ['task' => 'billing:renew', 'confirm' => true])
            ->assertStatus(200)
            ->assertJsonPath('status', 'dispatched');

        Queue::assertPushed(RunScheduledTaskJob::class);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'automation.run_manual',
            'target_id' => 'billing:renew',
            'severity' => 'warning',
        ]);
    }

    public function test_non_sensitive_run_needs_no_confirmation(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin, 'platform')
            ->postJson('/api/platform/automations/run', ['task' => 'email:fetch-inbox'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'dispatched');

        Queue::assertPushed(RunScheduledTaskJob::class);
    }

    public function test_double_execution_is_blocked_when_a_run_is_in_progress(): void
    {
        Queue::fake();

        ScheduledTaskRun::create([
            'task' => 'alerts:evaluate', 'status' => 'running',
            'started_at' => now()->subMinute(), 'environment' => 'testing',
        ]);

        $this->actingAs($this->superAdmin, 'platform')
            ->postJson('/api/platform/automations/run', ['task' => 'alerts:evaluate'])
            ->assertStatus(409)
            ->assertJson(['code' => 'already_running']);

        Queue::assertNothingPushed();
    }
}
