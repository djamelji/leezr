<?php

namespace Tests\Feature;

use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * ADR-823 — the default delivery-driver role grants what the work needs (own deliveries, status updates, the
 * personal space needs no permission) and nothing administrative or giving access to colleagues' data.
 */
class Adr823DriverRoleTest extends TestCase
{
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
}
