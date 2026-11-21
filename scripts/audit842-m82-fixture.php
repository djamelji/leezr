<?php
// Leezr 4.2 M8.2 recipe fixture — MARKED audit clone only. Fictitious Platform role with a LONG key (R-M81-2) and an
// operator holding it (password from the recipe secret file). Idempotent. Never touches historical accounts.
//   php scripts/audit842-m82-fixture.php <password-file>
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$key = 'recette847_role_avec_une_cle_tres_longue'; // 40 chars : > 20 (old actor_type limit), ≤ 50 (role validation)
$role = DB::table('platform_roles')->where('key', $key)->value('id')
    ?? DB::table('platform_roles')->insertGetId(['key' => $key, 'name' => 'Recette M8.2 (clé longue)', 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_permission')->where('platform_role_id', $role)->delete();
foreach (DB::table('platform_permissions')->whereIn('key', ['view_companies'])->pluck('id') as $p) {
    DB::table('platform_role_permission')->insert(['platform_role_id' => $role, 'platform_permission_id' => $p]);
}
$email = 'op847@platform.test';
$hash = Hash::make(trim(file_get_contents($argv[1])));
$id = DB::table('platform_users')->where('email', $email)->value('id');
if ($id) DB::table('platform_users')->where('id', $id)->update(['password' => $hash, 'updated_at' => now()]);
else $id = DB::table('platform_users')->insertGetId(['first_name' => 'Opérateur', 'last_name' => 'Clé longue', 'email' => $email, 'password' => $hash, 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $id, 'platform_role_id' => $role], ['created_at' => now(), 'updated_at' => now()]);
DB::table('two_factor_credentials')->where('authenticatable_type', 'App\\Platform\\Models\\PlatformUser')->where('authenticatable_id', $id)->delete();
echo "op847 ready (role key ".strlen($key)." chars)\n";
