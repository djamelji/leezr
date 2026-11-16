<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Presets\PresetApplication;
use App\Core\Presets\PresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-808 — one creation path behind a controlled switch, operational roles without implicit access, and
 * dormant rights that never become usable silently when a module is activated for the first time (direct or
 * as a dependency). Pivots are never changed by the protection ; 200/201 always checked with state.
 */
class Adr808InitializationDormantRightsTest extends TestCase
{
    use RefreshDatabase;

    private const WF = ['workforce', 'workforce_leave', 'workforce_documents', 'workforce_signature', 'logistics_shipments'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
    }

    private function company(string $tag): array
    {
        $c = Company::create(['name' => "D808 $tag", 'slug' => 'd808-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function member(Company $c, string $roleKey): User
    {
        $u = User::factory()->create();
        $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $c->id)->where('key', $roleKey)->value('id')]);

        return $u;
    }

    private function as(User $u, Company $c)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function perms(Company $c, string $roleKey): array
    {
        return CompanyRole::where('company_id', $c->id)->where('key', $roleKey)->first()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function rbacPreset(string $family, array $roles, array $jobdomains = ['logistique'], bool $publish = true): void
    {
        $p = PresetService::createDraft('rbac', ['family_key' => $family, 'label' => $family, 'jobdomain_key' => 'logistique', 'content' => ['roles' => $roles]], null);
        if ($publish) {
            PresetService::publish($p, null);
        }
        foreach ($jobdomains as $jd) {
            PresetService::setRecommendations($jd, [['kind' => 'rbac', 'family_key' => $family]]);
        }
    }

    private function logisticsRoles(): array
    {
        return [
            ['key' => 'owner', 'name' => 'Propriétaire', 'is_administrative' => true, 'bundles' => ['members.team_management', 'roles.governance', 'modules.management', 'shipments.operations']],
            ['key' => 'livreur', 'name' => 'Livreur', 'bundles' => ['members.team_view', 'shipments.delivery']],
            ['key' => 'chef', 'name' => 'Chef', 'is_administrative' => true, 'bundles' => ['shipments.operations']],
        ];
    }

    private function keepAccess(User $owner, Company $c, string $module)
    {
        $fp = $this->as($owner, $c)->getJson("/api/modules/$module/setup")->assertOk()->json('fingerprint');

        return $this->as($owner, $c)->putJson("/api/modules/$module/setup/access", ['changes' => [], 'fingerprint' => $fp]);
    }

    // ─── Controlled switch ──────────────────────────────────────────────────────────────────────

    public function test_switch_is_controlled_and_falls_back_to_the_historical_path(): void
    {
        $use = fn () => VersionedCompanyInitializer::shouldUse('logistique', 'FR');
        $this->assertFalse($use(), 'flag off');
        config(['jobdomains.versioned_init.enabled' => true]);
        $this->assertFalse($use(), 'flag on, no jobdomain listed : never global');
        config(['jobdomains.versioned_init.jobdomains' => ['logistique']]);
        $this->assertFalse($use(), 'preset absent');
        try {
            $this->rbacPreset('draft_only', $this->logisticsRoles(), ['logistique'], false);
            $this->fail('a draft can never be recommended');
        } catch (ValidationException) {
        }
        $this->assertFalse($use(), 'draft only (not published)');
        $this->rbacPreset('no_owner', [['key' => 'livreur', 'name' => 'Livreur', 'bundles' => ['shipments.delivery']]]);
        $this->assertFalse($use(), 'incomplete : no owner role → never a company without administration');
        $this->rbacPreset('shared', $this->logisticsRoles(), ['logistique']);
        $this->assertTrue($use());

        // Shared preset : the same family recommended to a second jobdomain works there too, only if listed.
        Jobdomain::create(['key' => 'svc808', 'label' => 'Services 808', 'is_active' => true, 'default_modules' => []]);
        PresetService::setRecommendations('svc808', [['kind' => 'rbac', 'family_key' => 'shared']]);
        $this->assertFalse(VersionedCompanyInitializer::shouldUse('svc808', 'FR'));
        config(['jobdomains.versioned_init.jobdomains' => ['logistique', 'svc808']]);
        $this->assertTrue(VersionedCompanyInitializer::shouldUse('svc808', 'FR'));
    }

    // ─── Versioned creation : owner administers, operational roles on hold ──────────────────────

    public function test_versioned_creation_owner_only_operational_roles_without_access_until_confirmation(): void
    {
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'logistics_shipments']]);
        $this->rbacPreset('logi808', $this->logisticsRoles());
        config(['jobdomains.versioned_init.enabled' => true, 'jobdomains.versioned_init.jobdomains' => ['logistique']]);
        [$c, $owner] = $this->company('V');

        $out = VersionedCompanyInitializer::initializeNew($c, 'logistique', ['rbac' => ['owner']], $owner->id);
        $this->assertEqualsCanonicalizing(['livreur', 'owner'], CompanyRole::where('company_id', $c->id)->pluck('key')->all(), 'chef (sensitive) not confirmed');
        $livreurId = CompanyRole::where('company_id', $c->id)->where('key', 'livreur')->value('id');
        // ADR-808.1 : every activated module (core included) holds the operational role's rights.
        $this->assertSame(['core.members' => [$livreurId], 'logistics_shipments' => [$livreurId]], $out['pending_access']);
        $setup = CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'logistics_shipments')->sole();
        $this->assertSame(CompanyModuleSetup::TO_CONFIGURE, $setup->status);
        $stored = $this->perms($c, 'livreur');
        $this->assertContains('shipments.view_own', $stored, 'stored on the role (pivot) …');

        $driver = $this->member($c, 'livreur');
        $this->as($driver, $c)->getJson('/api/my-deliveries')->assertForbidden(); // … but not effective
        $role = CompanyRole::where('company_id', $c->id)->where('key', 'livreur')->first();
        $this->assertNotContains('shipments.view_own', $role->effectivePermissionKeys());
        $this->assertNotContains('members.view', $role->effectivePermissionKeys(), 'ADR-808.1 : core rights held too');
        $this->as($owner, $c)->getJson('/api/shipments')->assertOk(); // owner administers immediately

        // The assistant shows the pending holder ; confirming the access (unchanged) makes it effective.
        $d = $this->as($owner, $c)->getJson('/api/modules/logistics_shipments/setup')->assertOk()->json();
        $this->assertNotEmpty(collect($d['roles'])->firstWhere('key', 'livreur')['pending']);
        $this->keepAccess($owner, $c, 'logistics_shipments')->assertOk();
        $this->assertNull($setup->fresh()->pending_access);
        $this->as($driver, $c)->getJson('/api/my-deliveries')->assertOk();
        $this->assertSame($stored, $this->perms($c, 'livreur'), 'no pivot change at any step');

        // Never twice, never on an existing company.
        $this->expectException(ValidationException::class);
        VersionedCompanyInitializer::initializeNew($c, 'logistique', ['rbac' => ['owner']], $owner->id);
    }

    public function test_historical_creation_is_unchanged(): void
    {
        [$c] = $this->company('L');
        JobdomainGate::assignToCompany($c, 'logistique');
        $this->assertSame(0, CompanyModuleSetup::where('company_id', $c->id)->count());
        $this->assertSame(0, PresetApplication::where('company_id', $c->id)->count());
        $driver = $this->member($c, 'driver');
        $this->as($driver, $c)->getJson('/api/my-deliveries')->assertOk(); // historical behaviour : effective at once
        $this->assertSame(1, CompanyRole::where('company_id', $c->id)->where('key', 'driver')->count(), 'no duplicate role');
    }

    // ─── Dormant rights ─────────────────────────────────────────────────────────────────────────

    private function workforceCompany(): array
    {
        Jobdomain::where('key', 'logistique')->update(['default_modules' => self::WF]); // entitled without purchase
        [$c, $owner] = $this->company('D');
        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce')['success']);
        $chef = CompanyRole::create(['company_id' => $c->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        // Historical (dormant) pivots : Leave and Documents rights while those modules were never active.
        $chef->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.leave_request', 'workforce.leave_approve', 'workforce_documents.view'])->pluck('id')->all());

        return [$c, $owner, $chef, $this->member($c, 'chef')];
    }

    public function test_dormant_rights_are_held_on_first_activation_direct_or_indirect(): void
    {
        [$c, $owner, $chef, $u] = $this->workforceCompany();
        $before = $this->perms($c, 'chef');

        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce_leave')['success']);
        $this->assertSame([(string) $chef->id => ['workforce.leave_approve', 'workforce.leave_request']],
            array_map(fn ($k) => $k, CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'workforce_leave')->value('pending_access')));
        $this->assertFalse($u->fresh()->hasCompanyPermission($c, 'workforce.leave_request'));
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertForbidden();
        $this->assertTrue($u->fresh()->hasCompanyPermission($c, 'workforce.view'), 'already active module : no loss');

        // Indirect activation : Signatures pulls Documents RH — the dormant Documents right is held too.
        $r = ModuleActivationEngine::enable($c, 'workforce_signature');
        $this->assertContains('workforce_documents', $r['data']['activated']);
        $this->assertSame(['workforce_documents.view'], CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'workforce_documents')->value('pending_access')[$chef->id]);
        $this->as($u, $c)->getJson('/api/workforce/documents/templates')->assertForbidden();

        // Configuration state consistent : not ready, the assistant shows the pending holder.
        $d = $this->as($owner, $c)->getJson('/api/modules/workforce_leave/setup')->assertOk()->json();
        $this->assertNotSame('ready', $d['status']);
        $this->assertEqualsCanonicalizing(['workforce.leave_approve', 'workforce.leave_request'], collect($d['roles'])->firstWhere('key', 'chef')['pending']);

        // Confirm Leave access in its assistant → effective ; Documents still held (its own decision).
        $this->keepAccess($owner, $c, 'workforce_leave')->assertOk();
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertOk();
        $this->as($u, $c)->getJson('/api/workforce/documents/templates')->assertForbidden();

        // Équipe → Rôles explicit decision on Documents for this role ends its pending.
        $fp = $this->as($owner, $c)->postJson('/api/company/role-capabilities/workforce_documents/preview',
            ['changes' => [['role_id' => $chef->id, 'capability' => 'workforce_documents.employee', 'grant' => true]]])->json('fingerprint');
        $this->as($owner, $c)->putJson('/api/company/role-capabilities/workforce_documents',
            ['changes' => [['role_id' => $chef->id, 'capability' => 'workforce_documents.employee', 'grant' => true]], 'fingerprint' => $fp])->assertOk();
        $this->as($u, $c)->getJson('/api/workforce/documents/templates')->assertOk();
        $this->assertSame($before, $this->perms($c, 'chef'), 'protection never touched the pivots');
    }

    public function test_reactivation_and_historically_active_modules_keep_their_rights(): void
    {
        [$c, $owner, $chef, $u] = $this->workforceCompany();
        // Leave active BEFORE (row exists, no assistant row = historical) : enabling again is not a first activation.
        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce_leave')['success']);
        $this->keepAccess($owner, $c, 'workforce_leave')->assertOk();
        ModuleActivationEngine::disable($c, 'workforce_leave');
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertForbidden(); // module off
        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce_leave')['success']);
        $this->assertNull(CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'workforce_leave')->value('pending_access'));
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertOk(); // restored, not held

        // A module historically active without assistant row (activated before ADR-803/808) : untouched.
        CompanyModuleSetup::where('company_id', $c->id)->where('module_key', 'workforce_leave')->delete();
        $this->as($u, $c)->getJson('/api/workforce/leaves/types')->assertOk();
    }

    public function test_pending_is_per_company_and_existing_companies_are_untouched(): void
    {
        [$a, , , $u] = $this->workforceCompany();
        ModuleActivationEngine::enable($a, 'workforce_leave'); // held in A

        // Same person in company B, where Leave was active before the role got its rights (historical).
        [$b] = $this->company('B');
        ModuleActivationEngine::enable($b, 'workforce');
        ModuleActivationEngine::enable($b, 'workforce_leave');
        $roleB = CompanyRole::create(['company_id' => $b->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $roleB->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.leave_request'])->pluck('id')->all());
        $b->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $roleB->id]);

        $this->assertFalse($u->fresh()->hasCompanyPermission($a, 'workforce.leave_request'));
        $this->assertTrue($u->fresh()->hasCompanyPermission($b, 'workforce.leave_request'));
        $this->as($u, $b)->getJson('/api/workforce/leaves/types')->assertOk();
        $this->as($u, $a)->getJson('/api/workforce/leaves/types')->assertForbidden();

        // Existing companies : the migration leaves every historical row without pending, no re-application.
        $this->assertSame(0, CompanyModuleSetup::whereNotNull('pending_access')->where('company_id', $b->id)->count());
        $this->expectException(ValidationException::class);
        config(['jobdomains.versioned_init.enabled' => true]);
        VersionedCompanyInitializer::initializeNew($b, 'logistique', ['rbac' => ['owner']], null);
    }
}
