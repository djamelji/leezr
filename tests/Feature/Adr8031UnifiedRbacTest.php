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
use App\Core\Presets\PresetService;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-803.1 — ONE RBAC behaviour whatever the entry point (role editor, Équipe → Rôles by capability,
 * module assistant, theme visibility, member role assignment) + capability semantics + trustworthy « ready ».
 */
class Adr8031UnifiedRbacTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private User $admin;          // administrative, NOT owner

    private CompanyRole $adminRole;

    private CompanyRole $chef;    // administrative target

    private CompanyRole $agent;   // operational target

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_planning', 'workforce_leave']]);
        [$this->company, $this->owner] = $this->makeCompany('A', 'logistique');
        $this->adminRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'gestion', 'name' => 'Gestion', 'is_administrative' => true]);
        $this->adminRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['roles.view', 'roles.manage', 'modules.manage', 'theme.view', 'theme.manage', 'workforce.view', 'workforce.timesheet_view', 'workforce.timesheet_submit', 'members.view'])->pluck('id')->all());
        $this->admin = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'user', 'company_role_id' => $this->adminRole->id]);
        $this->chef = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $this->agent = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'agent', 'name' => 'Agent', 'is_administrative' => false]);
    }

    private function makeCompany(string $tag, string $jd): array
    {
        $c = Company::create(['name' => "R803.1 $tag", 'slug' => 'r8031-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => $jd]);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
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

    private function ids(array $keys): array
    {
        return CompanyPermission::whereIn('key', $keys)->pluck('id')->all();
    }

    private function perms(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function capApply(User $u, array $changes, bool $confirm = false, ?string $fp = null)
    {
        $fp ??= $this->as($u)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => $changes])->json('fingerprint');

        return $this->as($u)->putJson('/api/company/role-capabilities/workforce', ['changes' => $changes, 'fingerprint' => $fp, 'confirm_affected' => $confirm]);
    }

    // ── 1. One delegation policy on the DIRECT role editor ──────────────────

    public function test_direct_editor_refuses_self_elevation(): void
    {
        $current = $this->adminRole->permissions()->pluck('company_permissions.id')->all();
        $this->as($this->admin)->putJson("/api/company/roles/{$this->adminRole->id}", ['permissions' => array_merge($current, $this->ids(['workforce.manage']))])
            ->assertStatus(422);
        $this->assertNotContains('workforce.manage', $this->perms($this->adminRole));
    }

    public function test_direct_editor_refuses_delegating_rights_not_held(): void
    {
        $this->as($this->admin)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['billing.manage'])])->assertStatus(422);
        $this->as($this->admin)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['roles.manage'])])->assertStatus(422); // held, but owner-only delegation
        $this->as($this->admin)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['workforce.view'])])->assertOk(); // held & delegable
        $this->assertSame(['workforce.view'], $this->perms($this->chef));
        $this->as($this->owner)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['roles.manage', 'billing.manage'])])->assertOk(); // owner delegates everything
    }

    public function test_direct_editor_refuses_removing_rights_the_actor_cannot_delegate(): void
    {
        $this->chef->syncPermissionsSafe($this->ids(['billing.manage', 'workforce.view']));
        $this->as($this->admin)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['workforce.view'])])->assertStatus(422);
        $this->assertContains('billing.manage', $this->perms($this->chef));
    }

    public function test_direct_editor_admin_right_on_operational_role_and_role_creation(): void
    {
        $this->as($this->owner)->putJson("/api/company/roles/{$this->agent->id}", ['permissions' => $this->ids(['roles.view'])])->assertStatus(422);
        $before = CompanyRole::count();
        $this->as($this->admin)->postJson('/api/company/roles', ['name' => 'Nouveau', 'is_administrative' => true, 'permissions' => $this->ids(['billing.manage'])])->assertStatus(422);
        $this->assertSame($before, CompanyRole::count(), 'nothing created when refused');
    }

    public function test_role_of_another_company_is_not_reachable(): void
    {
        [$b] = $this->makeCompany('B', 'logistique');
        $foreign = CompanyRole::create(['company_id' => $b->id, 'key' => 'x', 'name' => 'X', 'is_administrative' => true]);
        $this->as($this->owner)->putJson("/api/company/roles/{$foreign->id}", ['permissions' => $this->ids(['workforce.view'])])->assertNotFound();
        $this->as($this->owner)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => [['role_id' => $foreign->id, 'capability' => 'workforce.team', 'grant' => true]]])->assertStatus(422);
        $this->assertSame([], $this->perms($foreign));
    }

    public function test_theme_visibility_goes_through_the_same_policy(): void
    {
        $this->as($this->admin)->putJson('/api/theme/role-visibility', ['visibility' => [$this->adminRole->id => false]])->assertStatus(422); // own role
        $this->assertContains('theme.view', $this->perms($this->adminRole));
        $this->as($this->admin)->putJson('/api/theme/role-visibility', ['visibility' => [$this->agent->id => true]])->assertOk(); // held & delegable
        $this->assertSame(['theme.view'], $this->perms($this->agent));
    }

    public function test_assigning_a_role_more_powerful_than_oneself_is_refused(): void
    {
        $this->chef->syncPermissionsSafe($this->ids(['billing.manage']));
        $this->adminRole->permissions()->attach(CompanyPermission::where('key', 'members.manage')->value('id'));
        $u = User::factory()->create();
        $m = $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $this->agent->id]);
        $this->as($this->admin)->putJson("/api/company/members/{$m->id}", ['company_role_id' => $this->chef->id])->assertStatus(422);
        $this->assertSame($this->agent->id, $m->fresh()->company_role_id);
    }

    // ── 2. Équipe → Rôles by capability = same engine and policy as the assistant ─────

    public function test_roles_editor_by_capability_requires_roles_manage_and_applies_the_policy(): void
    {
        $viewer = User::factory()->create();
        $vr = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'lecture', 'name' => 'Lecture', 'is_administrative' => true]);
        $vr->syncPermissionsSafe($this->ids(['roles.view']));
        $this->company->memberships()->create(['user_id' => $viewer->id, 'role' => 'user', 'company_role_id' => $vr->id]);
        $this->as($viewer)->getJson('/api/company/role-capabilities/workforce')->assertOk()->assertJsonPath('can_change', false);
        $this->capApply($viewer, [['role_id' => $this->chef->id, 'capability' => 'workforce.timesheet_employee', 'grant' => true]])->assertForbidden();

        $p = $this->as($this->admin)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => [['role_id' => $this->chef->id, 'capability' => 'workforce.team', 'grant' => true]]])->json();
        $this->assertSame('beyond_your_rights', $p['plan'][0]['blocked']); // admin lacks workforce.manage
        $this->capApply($this->admin, [['role_id' => $this->chef->id, 'capability' => 'workforce.timesheet_employee', 'grant' => true]])->assertOk();
        $this->assertEqualsCanonicalizing(['workforce.timesheet_view', 'workforce.timesheet_submit'], $this->perms($this->chef));
        $this->capApply($this->admin, [['role_id' => $this->adminRole->id, 'capability' => 'workforce.timesheet_employee', 'grant' => false]])->assertStatus(422); // own role : no self change
    }

    public function test_historical_customisations_outside_the_changed_capability_are_preserved(): void
    {
        $this->chef->syncPermissionsSafe($this->ids(['members.view', 'workforce.leave_view_all']));
        $this->capApply($this->owner, [['role_id' => $this->chef->id, 'capability' => 'workforce.team', 'grant' => true]])->assertOk();
        $this->capApply($this->owner, [['role_id' => $this->chef->id, 'capability' => 'workforce.team', 'grant' => false]], true)->assertOk();
        $this->assertEqualsCanonicalizing(['members.view', 'workforce.leave_view_all'], $this->perms($this->chef));
    }

    public function test_partial_capability_is_reported_and_never_widened_implicitly(): void
    {
        $this->chef->syncPermissionsSafe($this->ids(['workforce.timesheet_view'])); // part of several capabilities
        $roles = collect($this->as($this->owner)->getJson('/api/company/role-capabilities/workforce')->json('roles'))->keyBy('id');
        $this->assertSame('partial', $roles[$this->chef->id]['holds']['workforce.timesheet_employee']);
        $this->capApply($this->owner, [['role_id' => $this->chef->id, 'capability' => 'workforce.manager', 'grant' => true]])->assertOk();
        $this->assertNotContains('workforce.timesheet_submit', $this->perms($this->chef), 'untouched partial capability not completed');
    }

    public function test_overlapping_capability_keeps_shared_permissions_on_revoke(): void
    {
        $this->capApply($this->owner, [
            ['role_id' => $this->chef->id, 'capability' => 'workforce.team', 'grant' => true],
            ['role_id' => $this->chef->id, 'capability' => 'workforce.time_validation', 'grant' => true],
        ])->assertOk();
        // team ∩ time_validation = {timesheet_view, time_manage} : kept by time_validation, still fully held.
        $p = $this->as($this->owner)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => [['role_id' => $this->chef->id, 'capability' => 'workforce.team', 'grant' => false]]])->json('plan.0');
        $this->assertNotContains('workforce.time_manage', $p['remove_keys']);
        $this->assertNotContains('workforce.timesheet_view', $p['remove_keys']);
        $this->assertNotContains('workforce.time_validation', array_column($p['affected'], 'key'));
    }

    public function test_concurrent_edit_through_the_classic_editor_invalidates_the_preview(): void
    {
        $changes = [['role_id' => $this->chef->id, 'capability' => 'workforce.timesheet_employee', 'grant' => true]];
        $fp = $this->as($this->owner)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => $changes])->json('fingerprint');
        $this->as($this->owner)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['workforce.view'])])->assertOk();
        $this->capApply($this->owner, $changes, false, $fp)->assertStatus(409)->assertJsonPath('code', 'stale_preview');
    }

    // ── 3. « ready » stays truthful ─────────────────────────────────────────

    private function ready(): void
    {
        ModuleEnabled::dispatch($this->company, 'workforce');
        $fp = $this->as($this->owner)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => []])->json('fingerprint');
        $this->as($this->owner)->putJson('/api/modules/workforce/setup/access', ['changes' => [], 'fingerprint' => $fp])->assertOk();
        $this->company->forceFill(['convention_confirmed_at' => now()->subMinute()])->save();
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'weekly_hours_reference', 35);
        \Illuminate\Support\Facades\DB::table('company_policies')->where('company_id', $this->company->id)->update(['updated_at' => now()->subMinute()]);
        $rev = $this->as($this->owner)->postJson('/api/modules/workforce/setup/steps', ['step' => 'working_time'])->json('revision');
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $rev])->assertOk()->assertJsonPath('status', 'ready');
    }

    private function setupStatus(): string
    {
        return $this->as($this->owner)->getJson('/api/modules/workforce/setup')->json('status');
    }

    public function test_ready_becomes_needs_review_when_access_changes_outside_the_assistant(): void
    {
        $this->ready();
        $this->as($this->owner)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['workforce.view', 'workforce.manage'])])->assertOk();
        $d = $this->as($this->owner)->getJson('/api/modules/workforce/setup')->json();
        $this->assertSame('needs_review', $d['status']);
        $this->assertContains('access_changed', $d['stale_reasons']);
        $this->assertSame('needs_review', collect($this->as($this->owner)->getJson('/api/modules')->json('modules'))->firstWhere('key', 'workforce')['setup_status']);
        // Re-confirming re-verifies and restores « ready ».
        $this->as($this->owner)->postJson('/api/modules/workforce/setup/confirm', ['revision' => $d['revision']])->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_ready_becomes_needs_review_when_a_role_is_deleted(): void
    {
        $this->as($this->owner)->putJson("/api/company/roles/{$this->chef->id}", ['permissions' => $this->ids(['workforce.view'])])->assertOk();
        $this->ready();
        $this->as($this->owner)->deleteJson("/api/company/roles/{$this->chef->id}")->assertOk();
        $this->assertSame('needs_review', $this->setupStatus());
    }

    public function test_ready_becomes_needs_review_when_settings_or_framework_change(): void
    {
        // ADR-804 : comparaison de la configuration EFFECTIVE (empreinte), plus d'horodatage.
        $this->ready();
        $this->travel(2)->seconds();
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'weekly_hours_reference', 35); // même valeur réécrite
        $this->company->forceFill(['convention_confirmed_at' => now()])->save(); // même cadre reconfirmé
        $this->assertSame('ready', $this->setupStatus(), 'a write without effective change is not a change (no false positive)');

        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'weekly_hours_reference', 39); // valeur changée
        $this->assertContains('settings_changed', $this->as($this->owner)->getJson('/api/modules/workforce/setup')->json('stale_reasons'));

        $this->ready();
        $this->company->forceFill(['convention_confirmed_at' => null])->save(); // cadre plus confirmé
        $reasons = $this->as($this->owner)->getJson('/api/modules/workforce/setup')->json('stale_reasons');
        $this->assertContains('missing:framework_confirmed', $reasons);
    }

    public function test_reactivation_requires_a_new_confirmation(): void
    {
        $this->ready();
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce')->delete();
        $this->assertSame('suspended', $this->setupStatus());
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        ModuleEnabled::dispatch($this->company, 'workforce');
        $this->assertSame('in_progress', $this->setupStatus());
        $this->assertContains('access', CompanyModuleSetup::first()->completed_steps);
    }

    // ── 4. Suggestions from a fictive published preset on a fictive JobDomain ─────────────

    public function test_fictive_preset_suggestion_becomes_effective_only_after_explicit_validation(): void
    {
        Jobdomain::create(['key' => 'fict_8031', 'label' => 'Fictif 803.1', 'default_modules' => []]);
        [$c, $owner] = $this->makeCompany('F', 'fict_8031');
        $chef = CompanyRole::create(['company_id' => $c->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $v = PresetService::createDraft('rbac', ['family_key' => 'fict_8031_rbac', 'label' => 'Rôles fictifs', 'content' => ['roles' => [['key' => 'chef', 'name' => 'Chef', 'is_administrative' => true, 'bundles' => ['workforce.manager']]]]], null);
        PresetService::publish($v, null);
        PresetService::setRecommendations('fict_8031', [['kind' => 'rbac', 'family_key' => 'fict_8031_rbac']]);
        ModuleEnabled::dispatch($c, 'workforce');
        $d = $this->as($owner, $c)->getJson('/api/modules/workforce/setup')->assertOk()->json();
        $this->assertTrue($d['suggestion']['available']);
        $this->assertSame(['workforce.manager'], $d['suggestion']['roles'][$chef->id]);
        $this->assertSame([], $chef->fresh()->permissions()->pluck('key')->all(), 'a suggestion grants nothing');
        $fp = $this->as($owner, $c)->postJson('/api/modules/workforce/setup/access/preview', ['changes' => [['role_id' => $chef->id, 'capability' => 'workforce.manager', 'grant' => true]]])->json('fingerprint');
        $this->as($owner, $c)->putJson('/api/modules/workforce/setup/access', ['changes' => [['role_id' => $chef->id, 'capability' => 'workforce.manager', 'grant' => true]], 'fingerprint' => $fp])->assertOk();
        // ADR-807 : the composite capability grants only the permissions of ACTIVE modules — Planning / Leave
        // are not active here, so their rights are not given (they are decided in their own assistant later).
        $keys = $chef->fresh()->permissions()->pluck('key')->all();
        $this->assertContains('workforce.view', $keys);
        $this->assertNotContains('workforce.planning_publish', $keys);
        $cap = collect($d['capabilities'])->firstWhere('key', 'workforce.manager');
        $this->assertEqualsCanonicalizing(['workforce_planning', 'workforce_leave'], $cap['dormant_modules']);
    }
}
