<?php

namespace Tests\Feature;

use App\Platform\Models\PlatformRole;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-852 (Leezr 4.2 release) — the exceptional company data export permission is granted explicitly only : the
 * deployment's system sync never offers it to the admin role (it offers every other catalog permission, ADR-832).
 */
class Adr852ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_only_permissions_are_never_offered_to_admin(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlatformPermissionCatalog::sync();
        $this->seed(\Database\Seeders\PlatformSeeder::class); // a second deployment

        $admin = PlatformRole::where('key', 'admin')->firstOrFail()->permissions()->pluck('key')->all();
        $this->assertNotContains('exceptional_company_export', $admin);
        $this->assertContains('manage_companies', $admin, 'other catalog permissions are still offered');
        $this->assertSame(['exceptional_company_export'], PlatformPermissionCatalog::EXPLICIT_GRANT_ONLY);
    }
}
