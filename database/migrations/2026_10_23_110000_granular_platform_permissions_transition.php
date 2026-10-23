<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-597 (LOT 3) — granular platform permissions, compatible transition.
 *
 * 1. Introduce `view_companies` (read-only companies) and grant it to every role that currently
 *    holds `manage_companies`, so existing managers keep read access (no silent narrowing).
 * 2. Remove dead permissions that no route ever enforced (they granted nothing, so detaching +
 *    deleting them changes zero effective access — no silent broadening, no narrowing).
 */
return new class extends Migration
{
    private array $dead = [
        'manage_billing_providers',
        'manage_billing_policies',
        'view_billing_audit',
        'security.view',
        'security.manage',
        'security.audit.view',
        'realtime.view',
        'realtime.manage',
        'view_documentation',
    ];

    public function up(): void
    {
        // 1. Ensure view_companies exists.
        DB::table('platform_permissions')->updateOrInsert(
            ['key' => 'view_companies'],
            ['label' => 'View Companies', 'module_key' => 'platform.companies', 'is_admin' => false, 'updated_at' => now(), 'created_at' => now()],
        );

        $viewCompaniesId = DB::table('platform_permissions')->where('key', 'view_companies')->value('id');
        $manageCompaniesId = DB::table('platform_permissions')->where('key', 'manage_companies')->value('id');

        // 2. Grant view_companies to every role already holding manage_companies.
        if ($viewCompaniesId && $manageCompaniesId) {
            $roleIds = DB::table('platform_role_permission')
                ->where('platform_permission_id', $manageCompaniesId)
                ->pluck('platform_role_id');

            $rows = $roleIds->map(fn ($rid) => [
                'platform_role_id' => $rid,
                'platform_permission_id' => $viewCompaniesId,
            ])->all();

            if ($rows !== []) {
                DB::table('platform_role_permission')->insertOrIgnore($rows);
            }
        }

        // 3. Remove dead permissions (detach from roles, then delete catalog rows).
        $deadIds = DB::table('platform_permissions')->whereIn('key', $this->dead)->pluck('id')->all();
        if ($deadIds !== []) {
            DB::table('platform_role_permission')->whereIn('platform_permission_id', $deadIds)->delete();
            DB::table('platform_permissions')->whereIn('id', $deadIds)->delete();
        }
    }

    public function down(): void
    {
        // Recreate the dead permission rows (label best-effort); grants are not restored.
        foreach ($this->dead as $key) {
            DB::table('platform_permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $key, 'module_key' => null, 'is_admin' => false, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Drop view_companies grants + row.
        $viewCompaniesId = DB::table('platform_permissions')->where('key', 'view_companies')->value('id');
        if ($viewCompaniesId) {
            DB::table('platform_role_permission')->where('platform_permission_id', $viewCompaniesId)->delete();
            DB::table('platform_permissions')->where('id', $viewCompaniesId)->delete();
        }
    }
};
