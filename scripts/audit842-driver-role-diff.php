<?php
// ADR-840 (M3.1) — READ ONLY : exact permission list of every historical delivery-driver role, before / after the
// ADR-825 remediation (same REMOVE list as rbac:driver-role-remediate), plus customisations and members. JSON on stdout.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Console\Commands\RbacDriverRoleRemediateCommand as R;
use Illuminate\Support\Facades\DB;
$rows = [];
foreach (DB::table('company_roles')->where('key', 'driver')->orderBy('company_id')->get() as $role) {
    $held = DB::table('company_role_permission')->join('company_permissions', 'company_permissions.id', '=', 'company_permission_id')
        ->where('company_role_id', $role->id)->orderBy('key')->pluck('key')->all();
    $remove = array_values(array_intersect(R::REMOVE, $held));
    $rows[] = [
        'company_id' => $role->company_id, 'company' => DB::table('companies')->where('id', $role->company_id)->value('name'),
        'role_id' => $role->id, 'role_name' => $role->name,
        'members' => DB::table('memberships')->where('company_role_id', $role->id)->count(),
        'customisations' => DB::table('company_audit_logs')->where('company_id', $role->company_id)->where('target_id', (string) $role->id)
            ->where('action', 'like', 'role.%')->whereNotIn('action', ['role.remediation_applied', 'role.remediation_restored', 'role.permission_revoked'])->count(),
        'before' => $held, 'remove' => $remove, 'after' => array_values(array_diff($held, $remove)),
    ];
}
echo json_encode(['database' => DB::connection()->getDatabaseName(), 'roles' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
