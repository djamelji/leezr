<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\PendingModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-840 (Leezr 4.2 M3.1) — a right awaiting an administrator's confirmation (ADR-808) is EXPLAINED (distinct state)
 * but never granted ; the Workforce settings page is a reading page without workforce.manage (UXC-07).
 */
class Adr840PendingAccessAndReadOnlySettingsTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private User $driver;

    private CompanyRole $driverRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Modules\ModuleRegistry::sync();
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        FieldDefinitionCatalog::sync();
        PendingModuleAccess::flush();

        $this->owner = User::factory()->create();
        $this->driver = User::factory()->create();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['logistics_shipments', 'workforce']]);
        $this->company = Company::create(['name' => 'Pending Co', 'slug' => 'pending-co', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->activateCompanyModules($this->company);
        foreach (['logistics_shipments', 'workforce'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($this->company);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->driverRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false]);
        $this->driverRole->permissions()->sync(CompanyPermission::whereIn('key', ['theme.view', 'shipments.view_own', 'shipments.manage_status'])->pluck('id')->all());
        $this->company->memberships()->create(['user_id' => $this->driver->id, 'role' => 'user', 'company_role_id' => $this->driverRole->id]);

        CompanyModuleSetup::updateOrCreate(['company_id' => $this->company->id, 'module_key' => 'logistics_shipments'],
            ['status' => CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => [], 'pending_access' => [(string) $this->driverRole->id => ['shipments.manage_status', 'shipments.view_own']]]);
        PendingModuleAccess::flush();
    }

    private function myRole(User $user): array
    {
        PendingModuleAccess::flush();
        $companies = $this->actingAs($user)->getJson('/api/my-companies')->assertOk()->json('companies');

        return collect($companies)->firstWhere('id', $this->company->id)['company_role'];
    }

    public function test_pending_rights_are_listed_apart_and_never_granted(): void
    {
        $role = $this->myRole($this->driver);
        $this->assertSame(['theme.view'], $role['permissions'], 'effective rights only');
        $this->assertEqualsCanonicalizing(['shipments.manage_status', 'shipments.view_own'], $role['pending_permissions']);

        PendingModuleAccess::flush();
        $this->actingAs($this->driver)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/my-deliveries')->assertForbidden();
    }

    public function test_confirmation_by_the_owner_makes_them_effective(): void
    {
        $fingerprint = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company/pending-access')->json('fingerprint');
        $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/pending-access/confirm', ['fingerprint' => $fingerprint])->assertOk();

        $role = $this->myRole($this->driver);
        $this->assertSame([], $role['pending_permissions']);
        $this->assertContains('shipments.view_own', $role['permissions']);
        PendingModuleAccess::flush();
        $this->actingAs($this->driver)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/my-deliveries')->assertOk();
    }

    public function test_a_right_the_role_does_not_hold_is_never_reported_pending(): void
    {
        CompanyModuleSetup::where('company_id', $this->company->id)->where('module_key', 'logistics_shipments')
            ->update(['pending_access' => json_encode([(string) $this->driverRole->id => ['shipments.view_own', 'shipments.delete']])]);
        $this->assertNotContains('shipments.delete', $this->myRole($this->driver)['pending_permissions'], 'pending ∩ stored rights only');
    }

    public function test_front_contract_pending_state_and_read_only_settings(): void
    {
        $store = file_get_contents(resource_path('js/core/stores/auth.js'));
        $this->assertMatchesRegularExpression('/hasPermission\(key\) \{\s*if \(this\.isOwner\) return true\s*return Array\.isArray\(this\.permissions\) && this\.permissions\.includes\(key\)/', $store,
            'hasPermission never reads the pending list');
        $guards = file_get_contents(resource_path('js/plugins/1.router/guards.js'));
        $this->assertSame(2, substr_count($guards, "reason: 'pending'"), 'permission + anyPermission explain a pending right');
        $this->assertStringContainsString('company-access-pending', file_get_contents(resource_path('js/pages/company/403.vue')));

        $settings = file_get_contents(resource_path('js/pages/company/workforce/settings/[tab].vue'));
        $this->assertStringContainsString("const canManage = computed(() => can('workforce.manage'))", $settings);
        $this->assertStringContainsString('workforce-settings-readonly', $settings);
        $this->assertSame(3, substr_count($settings, ':read-only="!canManage"'), 'working time + both rule tables locked');
        $this->assertStringNotContainsString(":disabled=\"!can('workforce.manage')\"\n                @click=\"saveWeekStart\"", $settings);
        foreach (['_ContractSettingsSection.vue' => "v-if=\"can('settings.manage')\"", '_SignatureSettingsSection.vue' => "v-if=\"can('workforce_signature.admin')\""] as $file => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(resource_path("js/pages/company/workforce/settings/$file")), "$file hides forbidden commands");
        }
    }

    public function test_manager_with_view_only_reads_settings_but_cannot_write(): void
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'mgr', 'name' => 'Manager', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view'])->pluck('id')->all());
        $manager = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $manager->id, 'role' => 'user', 'company_role_id' => $role->id]);

        $h = ['X-Company-Id' => $this->company->id];
        $this->actingAs($manager)->withHeaders($h)->getJson('/api/workforce/working-time-setup')->assertOk();
        $this->actingAs($manager)->withHeaders($h)->putJson('/api/workforce/working-time-setup', ['values' => []])->assertForbidden();
        $this->actingAs($manager)->withHeaders($h)->putJson('/api/workforce/config/week-start', ['week_start_day' => 1])->assertForbidden();
    }
}
