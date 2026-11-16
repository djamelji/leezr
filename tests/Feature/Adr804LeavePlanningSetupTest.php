<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Events\ModuleEnabled;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Workforce\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-804 — the common assistant on two more modules (Congés, Planning) : adaptive steps, required vs recommended,
 * same RBAC engine / delegation policy, effective-settings fingerprint, leave routes gated by the leave module.
 */
class Adr804LeavePlanningSetupTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private CompanyRole $chef;

    private CompanyRole $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave', 'workforce_planning']]);
        [$this->company, $this->owner] = $this->makeCompany('A');
        $this->chef = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $this->agent = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'agent', 'name' => 'Agent', 'is_administrative' => false]);
    }

    private function makeCompany(string $tag): array
    {
        $c = Company::create(['name' => "L804 $tag", 'slug' => 'l804-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($c);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function as(User $u, ?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->company)->id]);
    }

    private function perms(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function start(string $module): array
    {
        ModuleEnabled::dispatch($this->company, $module); // new activation (seeds leave types for workforce_leave)

        return $this->as($this->owner)->getJson("/api/modules/$module/setup")->assertOk()->json();
    }

    private function apply(string $module, array $changes, bool $confirm = false)
    {
        $fp = $this->as($this->owner)->postJson("/api/modules/$module/setup/access/preview", ['changes' => $changes])->json('fingerprint');

        return $this->as($this->owner)->putJson("/api/modules/$module/setup/access", ['changes' => $changes, 'fingerprint' => $fp, 'confirm_affected' => $confirm]);
    }

    private function member(CompanyRole $role): User
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    // ── adaptive contract ───────────────────────────────────────────────

    public function test_steps_follow_each_module_needs(): void
    {
        $leave = $this->start('workforce_leave');
        $planning = $this->start('workforce_planning');
        $this->assertSame(['access', 'leave_types'], $leave['steps']);
        $this->assertSame(['access'], $planning['steps'], 'no mandatory setting → access + confirmation only');
        $this->as($this->owner)->getJson('/api/modules/workforce_payroll/setup')->assertNotFound(); // no contract → no assistant
        $this->assertSame([['key' => 'workforce', 'name' => 'Workforce', 'active' => true, 'setup_status' => 'legacy']], $leave['module']['requires']);
    }

    public function test_capabilities_are_the_module_bundles_with_correct_scope_and_constraints(): void
    {
        $caps = collect($this->start('workforce_leave')['capabilities'])->keyBy('key');
        $this->assertSame('own', $caps['workforce.leave_employee']['scope']);
        $this->assertTrue($caps['workforce.leave_management']['administrative_only']);
        $this->assertFalse($caps['workforce.leave_management']['sensitive'], 'admin-only is a constraint, not « sensitive data »');
        $this->assertTrue($caps['workforce.leave_adjustment']['unwired']);
        $pcaps = collect($this->start('workforce_planning')['capabilities'])->keyBy('key');
        $this->assertSame('team', $pcaps['workforce.planning_employee']['scope']);
        $this->assertTrue($pcaps['workforce.planning_management']['administrative_only']);
    }

    // ── access : personal vs management vs approval stay distinct ───────

    public function test_leave_rights_stay_distinct_and_server_enforced(): void
    {
        $this->start('workforce_leave');
        $this->assertSame([], $this->perms($this->agent), 'activation grants nothing');
        $this->apply('workforce_leave', [
            ['role_id' => $this->agent->id, 'capability' => 'workforce.leave_employee', 'grant' => true],
            ['role_id' => $this->chef->id, 'capability' => 'workforce.leave_management', 'grant' => true],
        ])->assertOk();
        $this->assertSame(['workforce.leave_request'], $this->perms($this->agent));
        $p = $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/access/preview', ['changes' => [['role_id' => $this->agent->id, 'capability' => 'workforce.leave_management', 'grant' => true]]])->json('plan.0');
        $this->assertSame('operational_role', $p['blocked']);

        $agent = $this->member($this->agent);
        $chef = $this->member($this->chef);
        $this->as($agent)->getJson('/api/workforce/leaves/types')->assertOk();
        $this->as($agent)->postJson('/api/workforce/leaves/types', ['name' => 'X'])->assertForbidden();
        $this->as($agent)->postJson('/api/workforce/leaves/1/approve')->assertForbidden();
        $this->as($chef)->postJson('/api/workforce/leaves/999999/approve')->assertNotFound(); // allowed to reach, unknown request
    }

    public function test_delegation_policy_is_the_same(): void
    {
        $this->start('workforce_leave');
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'gest', 'name' => 'Gest', 'is_administrative' => true]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', ['modules.manage', 'roles.manage', 'workforce.leave_request'])->pluck('id')->all());
        $actor = $this->member($role);
        $p = $this->as($actor)->postJson('/api/modules/workforce_leave/setup/access/preview', ['changes' => [['role_id' => $this->chef->id, 'capability' => 'workforce.leave_management', 'grant' => true]]])->json('plan.0');
        $this->assertSame('beyond_your_rights', $p['blocked']);
        $p2 = $this->as($actor)->postJson('/api/modules/workforce_leave/setup/access/preview', ['changes' => [['role_id' => $this->chef->id, 'capability' => 'workforce.leave_employee', 'grant' => true]]])->json('plan.0');
        $this->assertNull($p2['blocked']);
    }

    public function test_partial_capability_and_multi_company(): void
    {
        $this->chef->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.leave_request', 'workforce.leave_approve'])->pluck('id')->all());
        $roles = collect($this->start('workforce_leave')['roles'])->keyBy('id');
        $this->assertSame('partial', $roles[$this->chef->id]['holds']['workforce.leave_management']);
        $this->assertSame('full', $roles[$this->chef->id]['holds']['workforce.leave_employee']);
        [$b] = $this->makeCompany('B');
        $foreign = CompanyRole::create(['company_id' => $b->id, 'key' => 'x', 'name' => 'X', 'is_administrative' => true]);
        $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/access/preview', ['changes' => [['role_id' => $foreign->id, 'capability' => 'workforce.leave_employee', 'grant' => true]]])->assertStatus(422);
    }

    // ── readiness : required vs recommended, effective settings ─────────

    public function test_leave_ready_needs_an_enabled_type_and_recommendations_never_block(): void
    {
        $d = $this->start('workforce_leave');
        $this->assertNotEmpty(LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->enabled()->get(), 'types seeded at activation');
        $req = collect($d['readiness'])->keyBy('key');
        $this->assertFalse($req['approver_assigned']['required']);
        $this->apply('workforce_leave', [])->assertOk();
        $rev = $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/steps', ['step' => 'leave_types'])->json('revision');
        $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/confirm', ['revision' => $rev])->assertOk()->assertJsonPath('status', 'ready'); // no approver : recommendation only

        // Same value rewritten → still ready ; an effective change (type disabled) → needs review.
        $type = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->enabled()->first();
        $type->forceFill(['requires_approval' => $type->requires_approval])->save();
        $this->assertSame('ready', $this->as($this->owner)->getJson('/api/modules/workforce_leave/setup')->json('status'));
        $type->forceFill(['enabled' => false])->save();
        $this->assertContains('settings_changed', $this->as($this->owner)->getJson('/api/modules/workforce_leave/setup')->json('stale_reasons'));
    }

    public function test_leave_not_ready_without_any_enabled_type(): void
    {
        $this->start('workforce_leave');
        LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['enabled' => false]);
        $this->apply('workforce_leave', [])->assertOk();
        $rev = $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/steps', ['step' => 'leave_types'])->json('revision');
        $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/confirm', ['revision' => $rev])->assertStatus(422)->assertJsonPath('missing', ['leave_type_enabled']);
    }

    public function test_planning_ready_after_access_only_and_recommendations_reported(): void
    {
        $this->start('workforce_planning');
        $this->apply('workforce_planning', [['role_id' => $this->chef->id, 'capability' => 'workforce.planning_management', 'grant' => true]])->assertOk();
        $d = $this->as($this->owner)->getJson('/api/modules/workforce_planning/setup')->json();
        $rec = collect($d['readiness'])->keyBy('key');
        $this->assertTrue($rec['publisher_assigned']['ok']);
        $this->assertFalse($rec['workforce_framework']['ok']); // read from Workforce, not duplicated ; not blocking
        $this->as($this->owner)->postJson('/api/modules/workforce_planning/setup/confirm', ['revision' => $d['revision']])->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_reactivation_keeps_types_and_rights_and_asks_confirmation(): void
    {
        $this->start('workforce_leave');
        $this->apply('workforce_leave', [['role_id' => $this->agent->id, 'capability' => 'workforce.leave_employee', 'grant' => true]])->assertOk();
        $rev = $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/steps', ['step' => 'leave_types'])->json('revision');
        $this->as($this->owner)->postJson('/api/modules/workforce_leave/setup/confirm', ['revision' => $rev])->assertOk();
        $types = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce_leave')->delete();
        $this->assertSame('suspended', $this->as($this->owner)->getJson('/api/modules/workforce_leave/setup')->json('status'));
        $this->as($this->member($this->agent))->getJson('/api/workforce/me/leaves')->assertForbidden(); // module gate (ADR-804)
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce_leave', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        ModuleEnabled::dispatch($this->company, 'workforce_leave');
        $this->assertSame('in_progress', $this->as($this->owner)->getJson('/api/modules/workforce_leave/setup')->json('status'));
        $this->assertSame(['workforce.leave_request'], $this->perms($this->agent));
        $this->assertSame($types, LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'no duplicate seeding');
        $this->assertSame(1, CompanyModuleSetup::where('module_key', 'workforce_leave')->count());
    }

    public function test_leave_routes_are_gated_by_the_leave_module(): void
    {
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce_leave')->delete();
        foreach (['/api/workforce/leaves', '/api/workforce/leaves/types', '/api/workforce/me/leaves', '/api/workforce/me/leave-balances'] as $uri) {
            $this->as($this->owner)->getJson($uri)->assertForbidden();
        }
        $this->as($this->owner)->getJson('/api/workforce/employees')->assertOk(); // Workforce itself unaffected
    }
}
