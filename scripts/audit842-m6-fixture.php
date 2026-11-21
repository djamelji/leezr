<?php
// Leezr 4.2 M6 recipe fixture — MARKED audit clone only, fictitious companies 105 / 106 only.
//  - 105 : a dispatcher (dispatch843, permissions copied from the fictitious recipe role of company 1, read only) ;
//          the driver role's delivery rights confirmed (no pending access) so the logistics journey can be played ;
//          shipments created by the previous run of this recipe removed (reference prefix M6-).
// Password read from a file, never printed. Historical companies are never written.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
if (! DB::table('companies')->where('id', 105)->where('name', 'like', 'Recette 834 Transports Fictifs%')->exists()) { fwrite(STDERR, "fictitious company 105 not found\n"); exit(2); }
$hash = Hash::make(trim(file_get_contents($argv[1])));
$perms = DB::table('company_role_permission')->join('company_roles', 'company_roles.id', '=', 'company_role_permission.company_role_id')
    ->where('company_roles.company_id', 1)->where('company_roles.key', 'dispatcher')->pluck('company_role_permission.company_permission_id')->all();
$role = DB::table('company_roles')->where('company_id', 105)->where('key', 'recette843_dispatch')->value('id')
    ?? DB::table('company_roles')->insertGetId(['company_id' => 105, 'key' => 'recette843_dispatch', 'name' => 'Dispatcher (recette)', 'is_system' => false, 'is_administrative' => true, 'created_at' => now(), 'updated_at' => now()]);
DB::table('company_role_permission')->where('company_role_id', $role)->delete();
foreach ($perms as $p) DB::table('company_role_permission')->insert(['company_role_id' => $role, 'company_permission_id' => $p]);
$email = 'dispatch843@audit842.test';
$uid = DB::table('users')->where('email', $email)->value('id');
if ($uid) DB::table('users')->where('id', $uid)->update(['password' => $hash, 'updated_at' => now()]);
else $uid = DB::table('users')->insertGetId(['first_name' => 'Dina', 'last_name' => 'Recette', 'email' => $email, 'password' => $hash, 'password_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
DB::table('memberships')->updateOrInsert(['user_id' => $uid, 'company_id' => 105], ['role' => 'user', 'company_role_id' => $role, 'acceptance_pending_at' => null, 'created_at' => now(), 'updated_at' => now()]);
DB::table('company_module_setups')->where('company_id', 105)->update(['pending_access' => null]);
DB::table('shipments')->where('company_id', 105)->where('reference', 'like', 'M6-%')->delete();
DB::table('shipments')->where('company_id', 105)->where('notes', 'like', 'Recette M6%')->delete();
echo "M6 fixture ready (105 : dispatch843, driver rights confirmed)\n";
