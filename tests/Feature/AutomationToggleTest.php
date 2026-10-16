<?php

namespace Tests\Feature;

use App\Core\Automation\Jobs\RunScheduledTaskJob;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Automation\ScheduledTaskState;
use App\Core\Automation\SchedulerInstrumentation as SI;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automations cockpit — enable/disable is REAL (not cosmetic).
 *
 * A disabled task is skipped by the scheduler (->when filter), refused by manual run,
 * skipped by the job even if already dispatched, and surfaced as 'disabled' in the API.
 */
class AutomationToggleTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->admin = PlatformUser::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'ops@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->first());
    }

    private function acting()
    {
        return $this->actingAs($this->admin, 'platform');
    }

    public function test_task_is_enabled_by_default_without_a_row(): void
    {
        $this->assertTrue(ScheduledTaskState::isEnabled('email:fetch-inbox'));
    }

    public function test_disabling_persists_and_blocks_the_scheduler_filter(): void
    {
        $this->acting()
            ->postJson('/api/platform/automations/toggle', ['task' => 'email:fetch-inbox', 'enabled' => false, 'reason' => 'maintenance'])
            ->assertStatus(200)
            ->assertJson(['enabled' => false]);

        // Persisted with audit trail.
        $this->assertDatabaseHas('scheduled_task_states', ['task' => 'email:fetch-inbox', 'is_enabled' => false]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'automation.task_disabled', 'target_id' => 'email:fetch-inbox']);

        // The scheduler ->when() filter now returns false → the command is really skipped.
        $this->assertFalse((SI::enabled('email:fetch-inbox'))());
    }

    public function test_disabled_task_cannot_be_run_manually(): void
    {
        Queue::fake();
        ScheduledTaskState::setEnabled('email:fetch-inbox', false, 'ops');

        $this->acting()
            ->postJson('/api/platform/automations/run', ['task' => 'email:fetch-inbox'])
            ->assertStatus(422)
            ->assertJson(['code' => 'task_disabled']);

        Queue::assertNothingPushed();
    }

    public function test_index_reports_disabled_state(): void
    {
        ScheduledTaskState::setEnabled('email:fetch-inbox', false, 'ops');

        $tasks = collect($this->acting()->getJson('/api/platform/automations')->json('tasks'))->keyBy('name');

        $this->assertFalse($tasks['email:fetch-inbox']['enabled']);
        $this->assertSame('disabled', $tasks['email:fetch-inbox']['health']);
        $this->assertTrue($tasks['alerts:evaluate']['enabled']);
    }

    public function test_re_enabling_restores_execution(): void
    {
        ScheduledTaskState::setEnabled('email:fetch-inbox', false, 'ops');
        $this->assertFalse(ScheduledTaskState::isEnabled('email:fetch-inbox'));

        $this->acting()
            ->postJson('/api/platform/automations/toggle', ['task' => 'email:fetch-inbox', 'enabled' => true])
            ->assertStatus(200)
            ->assertJson(['enabled' => true]);

        $this->assertTrue(ScheduledTaskState::isEnabled('email:fetch-inbox'));
        $this->assertTrue((SI::enabled('email:fetch-inbox'))());
    }

    public function test_job_skips_a_disabled_task_even_if_dispatched(): void
    {
        ScheduledTaskState::setEnabled('email:fetch-inbox', false, 'ops');

        $run = ScheduledTaskRun::create([
            'task' => 'email:fetch-inbox', 'status' => 'running',
            'started_at' => now(), 'environment' => 'testing',
        ]);

        (new RunScheduledTaskJob('email:fetch-inbox', $run->id))->handle();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('disabled', $run->error);
    }

    public function test_toggle_unknown_task_is_404(): void
    {
        $this->acting()
            ->postJson('/api/platform/automations/toggle', ['task' => 'does:not-exist', 'enabled' => false])
            ->assertStatus(404);
    }

    public function test_reenabling_sensitive_task_requires_permission(): void
    {
        ScheduledTaskState::setEnabled('billing:renew', false, 'ops');

        // Operator with manage_automations but NOT run_sensitive_automations.
        $role = PlatformRole::create(['key' => 'ops_limited', 'name' => 'Ops Limited']);
        $role->permissions()->sync(
            \App\Platform\Models\PlatformPermission::whereIn('key', ['manage_automations'])->pluck('id')->toArray()
        );
        $user = PlatformUser::create([
            'first_name' => 'Lim', 'last_name' => 'Ops',
            'email' => 'lim@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $user->roles()->attach($role);

        // Disabling a sensitive task is allowed (safety brake)…
        $this->actingAs($user, 'platform')
            ->postJson('/api/platform/automations/toggle', ['task' => 'billing:renew', 'enabled' => false])
            ->assertStatus(200);

        // …but re-enabling it is not.
        $this->actingAs($user, 'platform')
            ->postJson('/api/platform/automations/toggle', ['task' => 'billing:renew', 'enabled' => true])
            ->assertStatus(403)
            ->assertJson(['code' => 'sensitive_permission_required']);
    }
}
