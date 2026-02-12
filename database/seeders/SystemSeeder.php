<?php

namespace Database\Seeders;

use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\RBAC\PermissionCatalog;
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

        // ─── Cleanup stale permissions ───────────────────────────
        $this->cleanupStalePermissions();
    }

    private function cleanupStalePermissions(): void
    {
        $catalogKeys = PermissionCatalog::keys();

        $stale = PlatformPermission::whereNotIn('key', $catalogKeys)->get();

        if ($stale->isNotEmpty()) {
            $keys = $stale->pluck('key')->toArray();
            Log::warning('SystemSeeder: removing stale permissions not in PermissionCatalog', ['keys' => $keys]);
            PlatformPermission::whereNotIn('key', $catalogKeys)->delete();
        }
    }
}
