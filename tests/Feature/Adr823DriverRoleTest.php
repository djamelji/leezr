<?php

namespace Tests\Feature;

use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-823 — the default delivery-driver role grants what the work needs (own deliveries, status updates, the
 * personal space needs no permission) and nothing administrative or giving access to colleagues' data.
 */
class Adr823DriverRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_driver_role_is_least_privilege(): void
    {
        $roles = JobdomainRegistry::get('logistique')['default_roles'] ?? [];
        $driver = $roles['driver'] ?? null;
        $this->assertNotNull($driver, 'driver role defined');
        $perms = ModuleRegistry::resolveBundles($driver['bundles']);
        foreach (['members.view', 'settings.view', 'documents.view', 'members.manage', 'settings.manage', 'roles.view', 'workforce.view'] as $p) {
            $this->assertNotContains($p, $perms, "driver must not hold $p by default");
        }
        foreach (['shipments.view_own', 'shipments.manage_status'] as $p) {
            $this->assertContains($p, $perms, "driver needs $p");
        }
    }

    /** ADR-824 — the historical driver audit is read-only, proposes without applying, prints no personal data. */
    public function test_driver_role_audit_is_read_only_and_previews_remediation(): void
    {
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $c = \App\Core\Models\Company::create(['name' => 'Hist', 'slug' => 'hist-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $role = \App\Company\RBAC\CompanyRole::create(['company_id' => $c->id, 'key' => 'driver', 'name' => 'Chauffeur', 'is_system' => true, 'is_administrative' => false]);
        $role->permissions()->sync(\App\Company\RBAC\CompanyPermission::whereIn('key', ['members.view', 'documents.view', 'shipments.view_own', 'shipments.manage_status'])->pluck('id'));
        $before = $role->permissions()->pluck('key')->sort()->values()->all();

        $this->artisan('rbac:driver-role-audit', ['--company' => [$c->id]])->assertSuccessful();
        \Illuminate\Support\Facades\Artisan::call('rbac:driver-role-audit', ['--company' => [$c->id]]);
        $out = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertFalse($out['applied']);
        $row = $out['roles'][0];
        $this->assertEqualsCanonicalizing(['members.view', 'documents.view'], $row['remediation_preview']['remove']);
        $this->assertEqualsCanonicalizing(['shipments.view_own', 'shipments.manage_status'], $row['needed_for_delivery']);
        $this->assertStringStartsWith('none', $row['delivery_impact_of_removal']);
        $this->assertSame($before, $role->fresh()->permissions()->pluck('key')->sort()->values()->all(), 'nothing revoked');
        $this->assertArrayNotHasKey('email', $row);
    }
}
