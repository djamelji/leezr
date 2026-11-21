<?php
// Leezr 4.2 M3 recipe fixture — MARKED audit clone only. Fictitious companies only :
//  - 105 « Recette 834 Transports Fictifs » (logistique, Pro) : manager843, driver843 (existing least-privilege driver role), salarie843 ;
//    the owner's password is set from the file (fictitious account created by the ADR-834 recipe) ;
//  - 106 « Audit 842 Services » (Business, payroll active) : pay843 (payroll role) ;
//  - clone-only catalogue setting : Payroll requires the Business plan (min_plan_override) → « not in your plan » for 105.
// Role permission sets are copied from the fictitious recipe roles already used by the audit (company 1, read only).
// Password read from a file, never printed. Historical companies are never written.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
foreach ([105 => 'Recette 834 Transports Fictifs%', 106 => 'Audit 842 Services%'] as $id => $like) {
    if (! DB::table('companies')->where('id', $id)->where('name', 'like', $like)->exists()) { fwrite(STDERR, "fictitious company $id not found\n"); exit(2); }
}
$hash = Hash::make(trim(file_get_contents($argv[1])));
$perms = fn (string $sourceKey) => DB::table('company_role_permission')->join('company_roles', 'company_roles.id', '=', 'company_role_permission.company_role_id')
    ->where('company_roles.company_id', 1)->where('company_roles.key', $sourceKey)->pluck('company_role_permission.company_permission_id')->all();
$role = function (int $company, string $key, string $name, bool $admin, array $permIds) {
    $id = DB::table('company_roles')->where('company_id', $company)->where('key', $key)->value('id')
        ?? DB::table('company_roles')->insertGetId(['company_id' => $company, 'key' => $key, 'name' => $name, 'is_system' => false, 'is_administrative' => $admin, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_role_permission')->where('company_role_id', $id)->delete();
    foreach ($permIds as $p) DB::table('company_role_permission')->insert(['company_role_id' => $id, 'company_permission_id' => $p]);
    return $id;
};
$member = function (int $company, string $email, string $first, int $roleId) use ($hash) {
    $uid = DB::table('users')->where('email', $email)->value('id');
    if ($uid) DB::table('users')->where('id', $uid)->update(['password' => $hash, 'updated_at' => now()]);
    else $uid = DB::table('users')->insertGetId(['first_name' => $first, 'last_name' => 'Recette', 'email' => $email, 'password' => $hash, 'password_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('memberships')->updateOrInsert(['user_id' => $uid, 'company_id' => $company], ['role' => 'user', 'company_role_id' => $roleId, 'acceptance_pending_at' => null, 'created_at' => now(), 'updated_at' => now()]);
};
$manager = $role(105, 'recette843_manager', 'Responsable (recette)', true, $perms('recette-manager'));
$salarie = $role(105, 'recette843_salarie', 'Salarié (recette)', false, $perms('recette-salarie'));
$pay = $role(106, 'recette843_paie', 'Responsable paie (recette)', true, $perms('recette-pay'));
$driverRole = DB::table('company_roles')->where('company_id', 105)->where('key', 'driver')->value('id');
$member(105, 'manager843@audit842.test', 'Manon', $manager);
$member(105, 'salarie843@audit842.test', 'Sacha', $salarie);
$member(105, 'driver843@audit842.test', 'Dany', $driverRole);
$member(106, 'pay843@audit842.test', 'Paula', $pay);
DB::table('users')->where('email', 'camille.recette834@fictif.test')->update(['password' => $hash]);
DB::table('platform_modules')->where('key', 'workforce_payroll')->update(['min_plan_override' => 'business']);
echo "M3 fixture ready (105: manager/salarie/driver ; 106: pay ; payroll ≥ business on the clone)\n";
