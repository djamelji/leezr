<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Presets\PresetService;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-803 — guided module configuration (pilot : workforce). One RBAC, one activation engine, one source of
 * permissions (the manifest bundles). Every rule is checked over HTTP, including the refusals.
 */
class Adr803ModuleSetupTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private CompanyRole $manager;   // administrative custom role

    private CompanyRole $operator;  // operational custom role

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave']]);
        [$this->company, $this->owner] = $this->makeCompany('A');
        $this->manager = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $this->operator = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'agent', 'name' => 'Agent', 'is_administrative' => false]);
    }

    private function makeCompany(string $tag): array
    {
        $c = Company::create(['name' => "S803 $tag", 'slug' => 's803-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'] as $m) {
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

    private function member(array $permissions, bool $administrative = true): User
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r'.uniqid(), 'name' => 'Actor', 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function perms(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function start(): array
    {
        ModuleEnabled::dispatch($this->company, 'workforce'); // a NEW activation → to_configure

        return $this->as($this->owner)->getJson('/api/modules/workforce/setup')->assertOk()->json();
    }

    private function apply(User $actor, array $changes, ?string $fp = null, bool $confirm = false)
    {
        $fp ??= $this->as($actor)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => $changes])->json('fingerprint');

        return $this->as($actor)->putJson('/api/modules/workforce/setup/access', ['changes' => $changes, 'fingerprint' => $fp, 'confirm_affected' => $confirm]);
    }

    // ── activation & contract ─────────────────────────────────────────────

    public function test_new_activation_starts_to_configure_without_granting_any_right(): void
    {
        $before = [$this->perms($this->manager), $this->perms($this->operator)];
        $d = $this->start();
        $this->assertSame('to_configure', $d['status']);
        $this->assertSame($before, [$this->perms($this->manager), $this->perms($this->operator)]);
        $caps = collect($d['capabilities'])->keyBy('key');
        $this->assertTrue($caps['workforce.hr_management']['sensitive']);
        $this->assertSame('own', $caps['workforce.timesheet_employee']['scope']);
        $this->assertSame('team', $caps['workforce.timesheet_team_approver']['scope']);
        $this->assertSame(6, $caps['workforce.team']['permission_count']);
        $this->assertArrayNotHasKey('permission_set', $caps['workforce.team']);
        $this->assertFalse($d['suggestion']['available']); // no preset published
    }

    public function test_historical_activation_is_legacy_never_blocked_and_reading_creates_nothing(): void
    {
        $d = $this->as($this->owner)->getJson('/api/modules/workforce/setup')->assertOk()->json();
        $this->assertSame('legacy', $d['status']);
        $this->assertSame(0, CompanyModuleSetup::count());
        $this->as($this->owner)->getJson('/api/workforce/employees')->assertOk(); // module keeps working
    }

    public function test_module_without_contract_has_no_assistant(): void
    {
        // ADR-804 : workforce_planning a désormais un contrat — workforce_documents n'en a pas.
        $this->as($this->owner)->getJson('/api/modules/workforce_documents/setup')->assertNotFound();
        $catalog = collect($this->as($this->owner)->getJson('/api/modules')->assertOk()->json('modules'));
        $this->assertNull($catalog->firstWhere('key', 'workforce_documents')['setup_status']);
        $this->assertSame('legacy', $catalog->firstWhere('key', 'workforce')['setup_status']);
    }

    // ── suggestions ───────────────────────────────────────────────────────

    public function test_preset_suggestion_is_proposed_never_applied(): void
    {
        $draft = PresetService::createDraft('rbac', ['family_key' => 'logistique_rbac', 'label' => 'Logistique', 'content' => ['roles' => [
            ['key' => 'chef', 'name' => 'Chef', 'is_administrative' => true, 'bundles' => ['workforce.manager', 'workforce.team']],
        ]]], null);
        PresetService::publish($draft, null);
        PresetService::setRecommendations('logistique', [['kind' => 'rbac', 'family_key' => 'logistique_rbac']]);
        $before = $this->perms($this->manager);
        $d = $this->start();
        $this->assertTrue($d['suggestion']['available']);
        $this->assertEqualsCanonicalizing(['workforce.manager', 'workforce.team'], $d['suggestion']['roles'][$this->manager->id]);
        $this->assertSame($before, $this->perms($this->manager), 'a suggestion grants nothing');
    }

    // ── access : grant / revoke ───────────────────────────────────────────

    public function test_capability_with_several_permissions_is_granted_whole_after_preview(): void
    {
        $this->start();
        $this->apply($this->owner, [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]])->assertOk();
        $this->assertEqualsCanonicalizing(['workforce.view', 'workforce.manage', 'workforce.time_manage', 'workforce.timesheet_view', 'workforce.planning_view', 'workforce.export'], $this->perms($this->manager));
        $this->assertContains('access', CompanyModuleSetup::first()->completed_steps);
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'module.access_configured']);
    }

    public function test_revoke_keeps_permissions_still_required_by_another_held_capability(): void
    {
        $this->start();
        $this->apply($this->owner, [
            ['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true],
            ['role_id' => $this->manager->id, 'capability' => 'workforce.timesheet_employee', 'grant' => true],
        ])->assertOk();
        $revoke = [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => false]];
        // ADR-803.1 : another fully-held capability would stop being held → explicit decision required.
        $this->apply($this->owner, $revoke)->assertStatus(422)->assertJsonPath('code', 'affected_capabilities');
        $this->apply($this->owner, $revoke, null, true)->assertOk();
        $this->assertEqualsCanonicalizing(['workforce.timesheet_view', 'workforce.timesheet_submit'], $this->perms($this->manager));
    }

    public function test_sensitive_capability_refused_on_an_operational_role(): void
    {
        $this->start();
        $p = $this->as($this->owner)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => [['role_id' => $this->operator->id, 'capability' => 'workforce.hr_management', 'grant' => true]]])->assertOk();
        $this->assertSame('operational_role', $p->json('plan.0.blocked'));
        $this->apply($this->owner, [['role_id' => $this->operator->id, 'capability' => 'workforce.hr_management', 'grant' => true]])->assertStatus(422);
        $this->assertSame([], $this->perms($this->operator));
    }

    public function test_personal_capability_on_operational_role(): void
    {
        $this->start();
        $this->apply($this->owner, [['role_id' => $this->operator->id, 'capability' => 'workforce.timesheet_employee', 'grant' => true]])->assertOk();
        $this->assertEqualsCanonicalizing(['workforce.timesheet_view', 'workforce.timesheet_submit'], $this->perms($this->operator));
    }

    public function test_module_manager_without_rbac_right_can_read_but_not_grant(): void
    {
        $this->start();
        $u = $this->member(['modules.manage']);
        $this->as($u)->getJson('/api/modules/workforce/setup')->assertOk()->assertJsonPath('can.configure_access', false);
        $this->apply($u, [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]])->assertForbidden();
        $this->assertSame([], $this->perms($this->manager));
    }

    public function test_actor_cannot_grant_rights_they_do_not_hold(): void
    {
        $this->start();
        $u = $this->member(['modules.manage', 'roles.manage', 'workforce.view']);
        $p = $this->as($u)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]]])->json();
        $this->assertSame('beyond_your_rights', $p['plan'][0]['blocked']);
        $this->apply($u, [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]])->assertStatus(422);
        $this->assertSame([], $this->perms($this->manager));
    }

    public function test_no_access_without_module_management_right(): void
    {
        $this->start();
        $u = $this->member(['workforce.view'], false);
        $this->as($u)->getJson('/api/modules/workforce/setup')->assertForbidden();
        $this->as($u)->postJson('/api/modules/workforce/setup/confirm', ['revision' => 0])->assertForbidden();
    }

    public function test_role_of_another_company_is_refused(): void
    {
        $this->start();
        [$other] = $this->makeCompany('B');
        $foreign = CompanyRole::create(['company_id' => $other->id, 'key' => 'chef', 'name' => 'Chef B', 'is_administrative' => true]);
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => [['role_id' => $foreign->id, 'capability' => 'workforce.team', 'grant' => true]]])->assertStatus(422);
        $this->assertSame([], $this->perms($foreign));
    }

    public function test_stale_preview_is_refused(): void
    {
        $this->start();
        $changes = [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]];
        $fp = $this->as($this->owner)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => $changes])->json('fingerprint');
        $this->manager->permissions()->attach(CompanyPermission::where('key', 'workforce.timesheet_view')->value('id')); // concurrent change
        $this->apply($this->owner, $changes, $fp)->assertStatus(409)->assertJsonPath('code', 'stale_preview');
    }

    public function test_reapplying_the_same_choice_is_idempotent(): void
    {
        $this->start();
        $c = [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]];
        $this->apply($this->owner, $c)->assertOk();
        $after = $this->perms($this->manager);
        $this->apply($this->owner, $c)->assertOk();
        $this->assertSame($after, $this->perms($this->manager));
    }

    // ── settings, readiness, confirmation ─────────────────────────────────

    private function makeReady(): int
    {
        $this->start();
        $this->apply($this->owner, [])->assertOk(); // decision « keep current access » is a decision
        $this->company->forceFill(['convention_confirmed_at' => now()])->save();
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'weekly_hours_reference', 35);

        return $this->as($this->owner)->postJson('/api/modules/workforce/setup/steps', ['step' => 'working_time'])->assertOk()->json('revision');
    }

    public function test_not_ready_while_prerequisites_are_missing(): void
    {
        $this->start();
        $this->apply($this->owner, [])->assertOk();
        $rev = CompanyModuleSetup::first()->revision;
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $rev])
            ->assertStatus(422)->assertJsonPath('code', 'not_ready');
        $this->assertNotSame('ready', CompanyModuleSetup::first()->status);
    }

    public function test_full_journey_ready_then_double_confirmation_is_idempotent(): void
    {
        $rev = $this->makeReady();
        $d = $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $rev])->assertOk()->json();
        $this->assertSame('ready', $d['status']);
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $d['revision']])->assertOk()->assertJsonPath('status', 'ready');
        $this->assertSame(1, DB::table('company_audit_logs')->where('action', 'module.configured')->count());
    }

    public function test_stale_revision_on_confirm(): void
    {
        $rev = $this->makeReady();
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $rev - 1])->assertStatus(409)->assertJsonPath('code', 'stale_revision');
    }

    public function test_interrupted_configuration_resumes_and_a_later_change_requires_reconfirmation(): void
    {
        $this->start();
        $this->apply($this->owner, [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]])->assertOk();
        $d = $this->as($this->owner)->getJson('/api/modules/workforce/setup')->json(); // « reopen later »
        $this->assertSame('in_progress', $d['status']);
        $this->assertSame(['access'], $d['completed_steps']);

        $rev = $this->makeReadyOnExisting();
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $rev])->assertOk();
        $this->apply($this->owner, [['role_id' => $this->manager->id, 'capability' => 'workforce.manager', 'grant' => true]])->assertOk();
        $this->assertSame('in_progress', CompanyModuleSetup::first()->status, 'a change after ready is confirmed again');
    }

    private function makeReadyOnExisting(): int
    {
        $this->company->forceFill(['convention_confirmed_at' => now()])->save();
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'weekly_hours_reference', 35);

        return $this->as($this->owner)->postJson('/api/modules/workforce/setup/steps', ['step' => 'working_time'])->assertOk()->json('revision');
    }

    public function test_access_step_cannot_be_marked_without_applying(): void
    {
        $this->start();
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/steps', ['step' => 'access'])->assertStatus(422);
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/steps', ['step' => 'payroll'])->assertStatus(422);
    }

    public function test_deactivation_suspends_and_reactivation_keeps_choices(): void
    {
        $rev = $this->makeReady();
        $this->apply($this->owner, [['role_id' => $this->manager->id, 'capability' => 'workforce.team', 'grant' => true]])->assertOk();
        $perms = $this->perms($this->manager);
        // Same effect as ModuleActivationEngine::disable : the activation reason disappears (ModuleGate's source).
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce')->delete();
        CompanyModule::where('company_id', $this->company->id)->where('module_key', 'workforce')->update(['is_enabled_for_company' => false]);
        ModuleDisabled::dispatch($this->company, 'workforce');
        $catalog = collect($this->as($this->owner)->getJson('/api/modules')->json('modules'));
        $this->assertSame('suspended', $catalog->firstWhere('key', 'workforce')['setup_status']);
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => 99])->assertStatus(422); // nothing is (re)activated implicitly

        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        CompanyModule::where('company_id', $this->company->id)->where('module_key', 'workforce')->update(['is_enabled_for_company' => true]);
        ModuleEnabled::dispatch($this->company, 'workforce');
        $this->assertSame($perms, $this->perms($this->manager));
        $this->assertSame(1, CompanyModuleSetup::count());
        $this->assertContains('access', CompanyModuleSetup::first()->completed_steps);
    }

    public function test_setup_endpoints_never_activate_or_buy(): void
    {
        $this->start();
        $before = CompanyModule::where('company_id', $this->company->id)->orderBy('module_key')->get(['module_key', 'is_enabled_for_company'])->toArray();
        $this->apply($this->owner, [])->assertOk();
        $this->as($this->owner)->getJson('/api/modules/workforce/setup')->assertOk();
        $this->assertSame($before, CompanyModule::where('company_id', $this->company->id)->orderBy('module_key')->get(['module_key', 'is_enabled_for_company'])->toArray());
        $this->assertSame(0, DB::table('company_addon_subscriptions')->where('company_id', $this->company->id)->count());
    }
}
