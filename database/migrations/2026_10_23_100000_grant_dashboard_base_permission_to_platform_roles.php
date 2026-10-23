<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-595 (LOT 1) — close the A-01 dashboard authorization hole with a compatible transition.
 *
 * Before this change every authenticated platform user implicitly reached the dashboard
 * (and its sensitive aggregates). We now gate the dashboard behind `view_dashboard` and the
 * two sensitive aggregates behind `view_system_health` / `view_usage_monitoring`.
 *
 * To avoid breaking the existing landing experience, we grant ONLY the low-sensitivity
 * `view_dashboard` to every existing platform role. The two sensitive permissions are
 * deliberately NOT auto-granted to restricted roles — that lock-down is the whole point of
 * the fix. `super_admin` bypasses checks; the seeded `admin` role already receives every
 * permission via PlatformSeeder.
 */
return new class extends Migration
{
    private array $permissions = [
        ['key' => 'view_dashboard', 'label' => 'View Dashboard'],
        ['key' => 'view_system_health', 'label' => 'View System Health'],
        ['key' => 'view_usage_monitoring', 'label' => 'View Usage Monitoring'],
    ];

    public function up(): void
    {
        // 1. Ensure the new permissions exist (idempotent — seeder catalog sync will no-op later).
        foreach ($this->permissions as $perm) {
            DB::table('platform_permissions')->updateOrInsert(
                ['key' => $perm['key']],
                [
                    'label' => $perm['label'],
                    'module_key' => 'platform.dashboard',
                    'is_admin' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // 2. Grant ONLY `view_dashboard` to every existing platform role (preserve the landing).
        $viewDashboardId = DB::table('platform_permissions')->where('key', 'view_dashboard')->value('id');
        if ($viewDashboardId === null) {
            return;
        }

        $rows = DB::table('platform_roles')->pluck('id')
            ->map(fn ($roleId) => [
                'platform_role_id' => $roleId,
                'platform_permission_id' => $viewDashboardId,
            ])
            ->all();

        if ($rows !== []) {
            // Pivot has a unique (role, permission) constraint — ignore existing grants.
            DB::table('platform_role_permission')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $ids = DB::table('platform_permissions')
            ->whereIn('key', array_column($this->permissions, 'key'))
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('platform_role_permission')->whereIn('platform_permission_id', $ids)->delete();
            DB::table('platform_permissions')->whereIn('id', $ids)->delete();
        }
    }
};
