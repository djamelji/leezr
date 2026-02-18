<?php

namespace Database\Seeders;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * System seeder — safe to run on any environment (local, staging, production).
 * 100% idempotent: running N times produces the exact same state.
 *
 * Seeds: Platform RBAC, Module catalog, Jobdomain catalog.
 * Does NOT seed demo data (users, companies, shipments).
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Platform RBAC (permissions, roles, super_admin) ─────
        $this->call(PlatformSeeder::class);

        // ─── Module catalog ──────────────────────────────────────
        ModuleRegistry::sync();

        // ─── Jobdomain catalog ───────────────────────────────────
        JobdomainRegistry::sync();

        // ─── Company permission catalog ──────────────────────────
        CompanyPermissionCatalog::sync();

        // ─── Field definitions catalog ─────────────────────────
        FieldDefinitionCatalog::sync();

        // ─── Cleanup stale permissions ───────────────────────────
        $this->cleanupStalePermissions();
    }

    private function cleanupStalePermissions(): void
    {
        // Platform permissions
        $platformKeys = PlatformPermissionCatalog::keys();
        $stalePlatform = PlatformPermission::whereNotIn('key', $platformKeys)->get();

        if ($stalePlatform->isNotEmpty()) {
            $keys = $stalePlatform->pluck('key')->toArray();
            Log::warning('SystemSeeder: removing stale platform permissions', ['keys' => $keys]);
            PlatformPermission::whereNotIn('key', $platformKeys)->delete();
        }

        // Company permissions
        $companyKeys = CompanyPermissionCatalog::keys();
        $staleCompany = CompanyPermission::whereNotIn('key', $companyKeys)->get();

        if ($staleCompany->isNotEmpty()) {
            $keys = $staleCompany->pluck('key')->toArray();
            Log::warning('SystemSeeder: removing stale company permissions', ['keys' => $keys]);
            CompanyPermission::whereNotIn('key', $companyKeys)->delete();
        }
    }
}
