<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-634 (Workforce 2.0 / W-D) — compatibilité RBAC : préserver les accès légitimes avant que les
 * nouvelles restrictions fines ne s'appliquent.
 *
 * STRICTEMENT ADDITIVE & IDEMPOTENTE :
 *  - `workforce.compensation_read` accordée aux rôles ADMINISTRATIFS détenant `workforce.view`
 *    (les RH/admins conservent la visibilité salaire désormais masquée par défaut) ;
 *  - `workforce.leave_view_all` accordée aux rôles détenant `workforce.leave_approve`
 *    (les approbateurs conservent la vue globale des congés).
 *
 * `owner` bypasse toutes les permissions → non concerné. `down()` ne révoque rien (compat non destructive).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = fn (string $key) => DB::table('company_permissions')->where('key', $key)->value('id');

        $viewId = $permId('workforce.view');
        $compId = $permId('workforce.compensation_read');
        $approveId = $permId('workforce.leave_approve');
        $viewAllId = $permId('workforce.leave_view_all');

        // compensation_read → rôles administratifs ayant workforce.view
        if ($viewId && $compId) {
            $roleIds = DB::table('company_role_permission as crp')
                ->join('company_roles as r', 'r.id', '=', 'crp.company_role_id')
                ->where('crp.company_permission_id', $viewId)
                ->where('r.is_administrative', true)
                ->pluck('r.id');
            foreach ($roleIds as $rid) {
                DB::table('company_role_permission')->insertOrIgnore([
                    'company_role_id' => $rid,
                    'company_permission_id' => $compId,
                ]);
            }
        }

        // leave_view_all → rôles ayant workforce.leave_approve
        if ($approveId && $viewAllId) {
            $roleIds = DB::table('company_role_permission')
                ->where('company_permission_id', $approveId)
                ->pluck('company_role_id');
            foreach ($roleIds as $rid) {
                DB::table('company_role_permission')->insertOrIgnore([
                    'company_role_id' => $rid,
                    'company_permission_id' => $viewAllId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Compat non destructive : on ne révoque pas les permissions accordées.
    }
};
