<?php
// Leezr 4.2 audit — sets ONE audit password on the chosen fictitious accounts of a MARKED audit clone, and creates
// a Platform « company creation operator ». Never runs elsewhere. Password read from a file, never printed.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$hash = Hash::make(trim(file_get_contents($argv[1])));
$company = ['owner@leezr.test', 'manager@leezr.test', 'salarie@leezr.test', 'consultation@leezr.test', 'bob@leezr.test', 'admin@leezr.test', 'ops@leezr.test', 'pay@leezr.test', 'rh-nopay@leezr.test', 'trial@leezr.test', 'pending@leezr.test', 'ownerb@leezr.test', 'vortex@leezr.test', 'sec-member@test.test', 'sec-owner@test.test', 'e2e.811.driver.42700@leezr.invalid'];
$n = DB::table('users')->whereIn('email', $company)->update(['password' => $hash, 'password_set_at' => now()]);
$platform = ['admin@leezr.com', 'gov-mgr@leezr.com', 'finance-reader@leezr.com', 'support-only@leezr.com', 'roles-only@leezr.com'];
$p = DB::table('platform_users')->whereIn('email', $platform)->update(['password' => $hash]);
// company creation operator (create_companies only)
$perm = DB::table('platform_permissions')->where('key', 'create_companies')->value('id');
$role = DB::table('platform_roles')->where('key', 'audit842_creator')->value('id') ?? DB::table('platform_roles')->insertGetId(['key' => 'audit842_creator', 'name' => 'Audit 842 — création sociétés', 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_permission')->updateOrInsert(['platform_role_id' => $role, 'platform_permission_id' => $perm]);
$u = DB::table('platform_users')->where('email', 'creator842@platform.test')->value('id') ?? DB::table('platform_users')->insertGetId(['first_name' => 'Créa', 'last_name' => 'Audit', 'email' => 'creator842@platform.test', 'password' => $hash, 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $u, 'platform_role_id' => $role]);
echo "company users $n, platform users $p + creator842\n";
