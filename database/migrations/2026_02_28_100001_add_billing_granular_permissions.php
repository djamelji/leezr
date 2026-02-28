<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-154: Add granular billing permissions for platform.billing module.
 *
 * Keeps existing view_billing + manage_billing unchanged.
 * Adds 3 new permissions and auto-assigns them to roles that already have manage_billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Sync all permissions from manifest (creates the 3 new ones)
        \App\Platform\RBAC\PlatformPermissionCatalog::sync();

        // 2. Auto-assign new permissions to roles that already have manage_billing
        $managePerm = DB::table('platform_permissions')->where('key', 'manage_billing')->first();

        if (!$managePerm) {
            return;
        }

        $roleIds = DB::table('platform_role_permission')
            ->where('platform_permission_id', $managePerm->id)
            ->pluck('platform_role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $newPermIds = DB::table('platform_permissions')
            ->whereIn('key', ['manage_billing_providers', 'manage_billing_policies', 'view_billing_audit'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($newPermIds as $permId) {
                DB::table('platform_role_permission')->insertOrIgnore([
                    'platform_role_id' => $roleId,
                    'platform_permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $newPermKeys = ['manage_billing_providers', 'manage_billing_policies', 'view_billing_audit'];

        // Remove pivot entries
        $permIds = DB::table('platform_permissions')
            ->whereIn('key', $newPermKeys)
            ->pluck('id');

        DB::table('platform_role_permission')
            ->whereIn('platform_permission_id', $permIds)
            ->delete();

        // Remove permissions
        DB::table('platform_permissions')
            ->whereIn('key', $newPermKeys)
            ->delete();
    }
};
