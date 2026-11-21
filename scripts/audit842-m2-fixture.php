<?php
// Leezr 4.2 M2 recipe fixture — MARKED audit clone only. Fictitious company 106 gets a plain member (member842@audit842.test,
// no administrative role) ; the fictitious accounts used by the recipe start without 2FA. Password read from a file, never printed.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
if (! DB::table('companies')->where('id', 106)->where('name', 'like', 'Audit 842 Services%')->exists()) { fwrite(STDERR, "fictitious company 106 not found\n"); exit(2); }
$hash = Hash::make(trim(file_get_contents($argv[1])));
$email = 'member842@audit842.test';
$uid = DB::table('users')->where('email', $email)->value('id');
if ($uid) {
    DB::table('users')->where('id', $uid)->update(['password' => $hash, 'updated_at' => now()]);
} else {
    $uid = DB::table('users')->insertGetId(['first_name' => 'Membre', 'last_name' => 'Recette', 'email' => $email, 'password' => $hash, 'password_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}
$role = DB::table('company_roles')->where('company_id', 106)->where('key', 'recette842_membre')->value('id')
    ?? DB::table('company_roles')->insertGetId(['company_id' => 106, 'key' => 'recette842_membre', 'name' => 'Membre (recette)', 'is_system' => false, 'is_administrative' => false, 'created_at' => now(), 'updated_at' => now()]);
DB::table('memberships')->updateOrInsert(['user_id' => $uid, 'company_id' => 106], ['role' => 'user', 'company_role_id' => $role, 'acceptance_pending_at' => null, 'created_at' => now(), 'updated_at' => now()]);
$owner = DB::table('users')->where('email', 'owner.at4f8@audit842.test')->value('id');
$viewer = DB::table('platform_users')->where('email', 'viewer842@platform.test')->value('id');
DB::table('two_factor_credentials')->where(fn ($q) => $q->where('authenticatable_type', 'App\Core\Models\User')->where('authenticatable_id', $owner))
    ->orWhere(fn ($q) => $q->where('authenticatable_type', 'App\Platform\Models\PlatformUser')->where('authenticatable_id', $viewer))->delete();
echo "member842 ready in company 106 (membership ".DB::table('memberships')->where('user_id', $uid)->value('id').") ; recipe accounts without 2FA\n";
