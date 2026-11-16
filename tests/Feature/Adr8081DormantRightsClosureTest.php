<?php

namespace Tests\Feature;

use App\Company\RBAC\CapabilityAccessService;
use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\PendingModuleAccess;
use App\Core\Presets\PresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-808.1 — closure of the dormant-rights exceptions : every server reader (API, nav, delegation), modules
 * WITHOUT assistant (Payroll), role-level confirmation through the delegation policy, the advanced editor's
 * third state, versioned creation holding every operational right, shared / pinned presets.
 */
class Adr8081DormantRightsClosureTest extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_signature', 'logistics_shipments', 'core.members'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]); // entitled without purchase
    }

    private function company(string $tag): array
    {
        $c = Company::create(['name' => "D8081 $tag", 'slug' => 'd8081-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce')['success']);
        $this->assertTrue(ModuleActivationEngine::enable($c, 'core.roles')['success'] ?? true);

        return [$c, $owner];
    }

    private function role(Company $c, string $key, array $perms, bool $admin = true): CompanyRole
    {
        $r = CompanyRole::create(['company_id' => $c->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $admin]);
        $r->syncPermissionsSafe(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());

        return $r;
    }

    private function member(Company $c, CompanyRole $r): User
    {
        $u = User::factory()->create();
        $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $r->id]);

        return $u;
    }

    private function as(User $u, Company $c)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function keys(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function navKeys(User $u, Company $c): array
    {
        return collect($this->as($u, $c)->getJson('/api/nav')->assertOk()->json('groups'))->flatMap(fn ($g) => collect($g['items'] ?? [])->flatMap(
            fn ($i) => array_merge([$i['key'] ?? null], array_column($i['children'] ?? [], 'key'))))->filter()->values()->all();
    }

    public function test_pending_right_opens_no_api_no_menu_no_client_permission_and_cannot_be_delegated(): void
    {
        [$c, $owner] = $this->company('A');
        $chef = $this->role($c, 'chef', ['workforce.view', 'roles.view', 'roles.manage', 'workforce.leave_request', 'workforce.leave_approve', 'workforce.leave_view_all', 'workforce.leave_manage']);
        $other = $this->role($c, 'other', ['workforce.view']);
        $u = $this->member($c, $chef);
        $navBefore = $this->navKeys($u, $c);

        ModuleActivationEngine::enable($c, 'workforce_leave'); // first activation → held
        $this->assertNotEmpty(PendingModuleAccess::suspendedFor($chef->fresh()));

        // API : direct resource access refused.
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertForbidden();
        $this->as($u, $c)->getJson('/api/workforce/leaves')->assertForbidden();
        // Client permission list and menus : nothing from the pending module.
        $perms = collect($this->as($u, $c)->getJson('/api/my-companies')->assertOk()->json())->flatten()->all();
        $this->assertNotContains('workforce.leave_approve', $perms);
        $this->assertSame($navBefore, $this->navKeys($u, $c), 'no menu opened by a pending right');
        // Delegation : a pending right is not held — cannot be granted to another role.
        $fp = $this->as($u, $c)->postJson('/api/company/role-capabilities/workforce_leave/preview', ['changes' => [['role_id' => $other->id, 'capability' => 'workforce.leave_management', 'grant' => true]]])->assertOk();
        $this->assertSame('beyond_your_rights', $fp->json('plan.0.blocked'));
        // … and the actor cannot confirm his own pending rights.
        $this->as($u, $c)->postJson("/api/company/roles/{$chef->id}/confirm-pending", ['module' => 'workforce_leave'])->assertStatus(422);
        $this->assertNotEmpty(PendingModuleAccess::suspendedFor($chef->fresh()));

        // The owner confirms (explicit, traced) → effective ; pivots untouched throughout.
        $before = $this->keys($chef);
        $this->as($owner, $c)->postJson("/api/company/roles/{$chef->id}/confirm-pending", ['module' => 'workforce_leave'])->assertOk()->assertJsonPath('confirmed.0.module', 'workforce_leave');
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertOk();
        $this->assertSame($before, $this->keys($chef));
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $c->id, 'action' => 'role.pending_confirmed']);
    }

    public function test_confirmation_respects_the_delegation_policy_of_the_actor(): void
    {
        [$c, $owner] = $this->company('B');
        $agent = $this->role($c, 'agent', ['workforce.view', 'workforce.leave_request', 'workforce.leave_approve']);
        $admin = $this->role($c, 'admin', ['workforce.view', 'roles.view', 'roles.manage', 'modules.manage']); // no Leave right himself
        $adminUser = $this->member($c, $admin);
        ModuleActivationEngine::enable($c, 'workforce_leave');

        // A non-owner administrator cannot make effective rights he could not grant (assistant or role editor).
        $this->as($adminUser, $c)->postJson("/api/company/roles/{$agent->id}/confirm-pending")->assertStatus(422);
        $fp = $this->as($adminUser, $c)->getJson('/api/modules/workforce_leave/setup')->assertOk()->json('fingerprint');
        $this->as($adminUser, $c)->putJson('/api/modules/workforce_leave/setup/access', ['changes' => [], 'fingerprint' => $fp])->assertOk();
        $this->assertNotEmpty(PendingModuleAccess::suspendedFor($agent->fresh()), 'refused : still pending');
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $c->id, 'action' => 'role.pending_confirmed']);
    }

    public function test_payroll_without_assistant_new_activation_is_held_and_historical_activation_untouched(): void
    {
        [$c, $owner] = $this->company('P');
        $rh = $this->role($c, 'rh', ['workforce.view', 'workforce.payroll_prepare', 'workforce.payroll_validate']);
        $u = $this->member($c, $rh);
        $r = ModuleActivationEngine::enable($c, 'workforce_payroll'); // also pulls Leave (dependency)
        $this->assertTrue($r['success']);
        $this->assertSame([(string) $rh->id => ['workforce.payroll_prepare', 'workforce.payroll_validate']],
            CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'workforce_payroll')->value('pending_access'));
        $this->as($u, $c)->getJson('/api/workforce/payroll/statistics')->assertForbidden();
        $this->as($owner, $c)->getJson('/api/modules/workforce_payroll/setup')->assertNotFound(); // still no assistant

        // Confirmation : role level, in Équipe → Rôles (the module's capability view shows the pending role).
        $d = $this->as($owner, $c)->getJson('/api/company/role-capabilities/workforce_payroll')->assertOk()->json();
        $this->assertNotEmpty(collect($d['roles'])->firstWhere('id', $rh->id)['pending']);
        $this->as($owner, $c)->postJson("/api/company/roles/{$rh->id}/confirm-pending", ['module' => 'workforce_payroll'])->assertOk();
        $this->as($u, $c)->getJson('/api/workforce/payroll/statistics')->assertOk();

        // Historically active Payroll (row present before) : re-enable never holds anything.
        [$h] = $this->company('H');
        $hr = $this->role($h, 'rh', ['workforce.view', 'workforce.payroll_prepare']);
        $hu = $this->member($h, $hr);
        CompanyModule::create(['company_id' => $h->id, 'module_key' => 'workforce_payroll', 'is_enabled_for_company' => false]); // was active once
        ModuleActivationEngine::enable($h, 'workforce_payroll');
        $this->assertNull(CompanyModuleSetup::where('company_id', $h->id)->where('module_key', 'workforce_payroll')->value('pending_access'));
        $this->as($hu, $h)->getJson('/api/workforce/payroll/statistics')->assertOk();
    }

    public function test_composite_rights_of_an_already_active_module_are_never_held(): void
    {
        [$c] = $this->company('C');
        // workforce.manager (Workforce) references Planning and Leave keys ; Workforce is active.
        $chef = $this->role($c, 'chef', ['workforce.view', 'workforce.timesheet_view', 'workforce.planning_view', 'workforce.leave_request']);
        $u = $this->member($c, $chef);
        ModuleActivationEngine::enable($c, 'workforce_planning');
        $held = PendingModuleAccess::suspendedFor($chef->fresh());
        $this->assertSame(['workforce.planning_view'], $held, 'only the activated module\'s own keys');
        $this->assertTrue($u->fresh()->hasCompanyPermission($c, 'workforce.view'));
        $this->assertTrue($u->fresh()->hasCompanyPermission($c, 'workforce.timesheet_view'));
        $this->assertEqualsCanonicalizing(['workforce.view', 'workforce.timesheet_view'],
            array_values(array_intersect($chef->fresh()->effectivePermissionKeys(), ['workforce.view', 'workforce.timesheet_view', 'workforce.planning_view'])));
        $this->assertSame([], array_diff($held, PendingModuleAccess::moduleKeys('workforce_planning')));
    }

    public function test_advanced_editor_exposes_the_third_state_and_paid_dependency_is_held(): void
    {
        [$c, $owner] = $this->company('E');
        $chef = $this->role($c, 'chef', ['workforce.view', 'workforce_documents.view', 'workforce_documents.generate']);
        ModuleActivationEngine::enable($c, 'workforce_signature'); // Documents RH (paid) activated as a dependency
        $roles = collect($this->as($owner, $c)->getJson('/api/company/roles')->assertOk()->json('roles'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['workforce_documents.generate', 'workforce_documents.view'], $roles['chef']['pending_permissions']);
        $this->assertSame([], $roles['owner']['pending_permissions'] ?? []);
        // Saving the role in the advanced editor (full list) does NOT confirm silently.
        $this->as($owner, $c)->putJson("/api/company/roles/{$chef->id}", ['name' => 'Chef 2', 'is_administrative' => true,
            'permissions' => $chef->permissions()->pluck('company_permissions.id')->all()])->assertOk();
        $this->assertNotEmpty(PendingModuleAccess::suspendedFor($chef->fresh()));
        $this->as($owner, $c)->postJson("/api/company/roles/{$chef->id}/confirm-pending")->assertOk();
        $this->assertSame([], collect($this->as($owner, $c)->getJson('/api/company/roles')->json('roles'))->firstWhere('key', 'chef')['pending_permissions']);
        // Tenant isolation : another company's role cannot be confirmed from here.
        [$o] = $this->company('O');
        $foreign = $this->role($o, 'chef', ['workforce.view']);
        $this->as($owner, $c)->postJson("/api/company/roles/{$foreign->id}/confirm-pending")->assertNotFound();
    }

    public function test_versioned_creation_holds_every_operational_right_with_shared_and_pinned_presets(): void
    {
        $roles = fn (string $driverName) => ['roles' => [
            ['key' => 'owner', 'name' => 'Propriétaire', 'is_administrative' => true, 'bundles' => ['members.team_management', 'roles.governance', 'shipments.operations']],
            ['key' => 'driver', 'name' => $driverName, 'bundles' => ['members.team_view', 'shipments.delivery']]]];
        $v1 = PresetService::createDraft('rbac', ['family_key' => 'logi_8081', 'label' => 'L', 'jobdomain_key' => 'logistique', 'content' => $roles('Chauffeur v1')], null);
        PresetService::publish($v1, null);
        $v2 = PresetService::createDraft('rbac', ['family_key' => 'logi_8081', 'label' => 'L', 'jobdomain_key' => 'logistique', 'content' => $roles('Chauffeur v2')], null);
        PresetService::publish($v2, null);
        // Pinned to v1 on logistique ; shared (latest) on a second jobdomain.
        config(['jobdomains.versioned_init.enabled' => true, 'jobdomains.versioned_init.jobdomains' => ['logistique', 'svc8081']]);
        // Pinned to v1, archived when v2 was published : recommendable but not applicable → historical path (no crash).
        PresetService::setRecommendations('logistique', [['kind' => 'rbac', 'family_key' => 'logi_8081', 'pinned_version' => 1]]);
        $this->assertFalse(VersionedCompanyInitializer::shouldUse('logistique', 'FR'));
        // Pinned to the published v2 on logistique ; the same family shared (latest) by a second jobdomain.
        PresetService::setRecommendations('logistique', [['kind' => 'rbac', 'family_key' => 'logi_8081', 'pinned_version' => 2]]);
        Jobdomain::create(['key' => 'svc8081', 'label' => 'Services', 'is_active' => true, 'default_modules' => []]);
        PresetService::setRecommendations('svc8081', [['kind' => 'rbac', 'family_key' => 'logi_8081']]);
        $this->assertTrue(VersionedCompanyInitializer::shouldUse('logistique', 'FR'));
        $this->assertTrue(VersionedCompanyInitializer::shouldUse('svc8081', 'FR'));

        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'logistics_shipments', 'logistics_fleet']]); // fleet = paid, not included
        $c = Company::create(['name' => 'V8081', 'slug' => 'v8081-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter']); // fleet = plan-gated there
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $out = VersionedCompanyInitializer::initializeNew($c, 'logistique', ['rbac' => ['owner']], $owner->id);
        $driver = CompanyRole::where('company_id', $c->id)->where('key', 'driver')->sole();
        $this->assertSame('Chauffeur v2', $driver->name, 'pinned published version applied');
        $this->assertContains('core.members', array_keys(array_filter($out['pending_access'])));
        $this->assertSame([], $driver->effectivePermissionKeys(), 'operational role : no implicit right at creation');
        $this->assertSame(0, \DB::table('company_addon_subscriptions')->where('company_id', $c->id)->count(), 'no paid module activated');
        $this->assertNotContains('logistics_fleet', CompanyModuleActivationReason::where('company_id', $c->id)->pluck('module_key')->all(), 'paid module never activated');

        $u = $this->member($c, $driver);
        $this->as($u, $c)->getJson('/api/company/members')->assertForbidden();
        $this->as($owner, $c)->postJson("/api/company/roles/{$driver->id}/confirm-pending")->assertOk();
        $this->as($u, $c)->getJson('/api/my-deliveries')->assertOk();
        $this->assertNotEmpty($driver->fresh()->effectivePermissionKeys());
    }
}
