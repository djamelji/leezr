<?php

use App\Company\RBAC\CompanyPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-642 (Workforce 2.0 / W-G2) — compatibilité RBAC pour les nouvelles capacités import/export.
 *
 * STRICTEMENT ADDITIVE & IDEMPOTENTE. On ne donne PAS ces capacités à tous les rôles :
 *  - `workforce.export` → rôles détenant déjà `workforce.view` (qui voit la liste peut l'exporter,
 *    dans la limite de son périmètre, sans données sensibles) ;
 *  - `workforce.import` → rôles ADMINISTRATIFS détenant déjà `workforce.manage` (création en masse).
 *
 * `owner` bypasse toutes les permissions → non concerné. `down()` ne révoque rien (compat non destructive).
 */
return new class extends Migration
{
    public function up(): void
    {
        // S'assure que les nouvelles permissions existent dans le catalogue avant de les accorder.
        CompanyPermissionCatalog::sync();

        $permId = fn (string $key) => DB::table('company_permissions')->where('key', $key)->value('id');

        $viewId = $permId('workforce.view');
        $manageId = $permId('workforce.manage');
        $exportId = $permId('workforce.export');
        $importId = $permId('workforce.import');

        // export → rôles ayant workforce.view
        if ($viewId && $exportId) {
            $roleIds = DB::table('company_role_permission')
                ->where('company_permission_id', $viewId)
                ->pluck('company_role_id');
            foreach ($roleIds as $rid) {
                DB::table('company_role_permission')->insertOrIgnore([
                    'company_role_id' => $rid,
                    'company_permission_id' => $exportId,
                ]);
            }
        }

        // import → rôles administratifs ayant workforce.manage
        if ($manageId && $importId) {
            $roleIds = DB::table('company_role_permission as crp')
                ->join('company_roles as r', 'r.id', '=', 'crp.company_role_id')
                ->where('crp.company_permission_id', $manageId)
                ->where('r.is_administrative', true)
                ->pluck('r.id');
            foreach ($roleIds as $rid) {
                DB::table('company_role_permission')->insertOrIgnore([
                    'company_role_id' => $rid,
                    'company_permission_id' => $importId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Compat non destructive : on ne révoque pas les permissions accordées.
    }
};
