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
// ADR-850 (M10) — the M2 recipe CHANGES a password and ENABLES 2FA : it uses its OWN accounts, recreated here at every
// run (reset password, no 2FA), so no other recipe ever depends on what M2 did. member842 / the owner / viewer842 are
// never modified by M2 any more.
$member = function (string $email, string $first) use ($hash, $role) {
    $id = DB::table('users')->where('email', $email)->value('id');
    if ($id) {
        DB::table('users')->where('id', $id)->update(['password' => $hash, 'updated_at' => now()]);
    } else {
        $id = DB::table('users')->insertGetId(['first_name' => $first, 'last_name' => 'Recette M2', 'email' => $email, 'password' => $hash, 'password_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('memberships')->updateOrInsert(['user_id' => $id, 'company_id' => 106], ['role' => 'user', 'company_role_id' => $role, 'acceptance_pending_at' => null, 'suspended_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('two_factor_credentials')->where('authenticatable_type', 'App\\Core\\Models\\User')->where('authenticatable_id', $id)->delete();

    return $id;
};
$pwd = $member('pwd842@audit842.test', 'Mot de passe');
$twofa = $member('twofa842@audit842.test', 'Double facteur');
$viewerRole = DB::table('platform_roles')->where('key', 'audit842_viewer')->value('id');
$op = DB::table('platform_users')->where('email', 'twofa842@platform.test')->value('id');
if ($op) {
    DB::table('platform_users')->where('id', $op)->update(['password' => $hash, 'updated_at' => now()]);
} else {
    $op = DB::table('platform_users')->insertGetId(['first_name' => 'Double facteur', 'last_name' => 'Recette M2', 'email' => 'twofa842@platform.test', 'password' => $hash, 'created_at' => now(), 'updated_at' => now()]);
}
if ($viewerRole) {
    DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $op, 'platform_role_id' => $viewerRole], ['created_at' => now(), 'updated_at' => now()]);
}
DB::table('two_factor_credentials')->where('authenticatable_type', 'App\\Platform\\Models\\PlatformUser')->where('authenticatable_id', $op)->delete();

$owner = DB::table('users')->where('email', 'owner.at4f8@audit842.test')->value('id');
$viewer = DB::table('platform_users')->where('email', 'viewer842@platform.test')->value('id');
DB::table('two_factor_credentials')->where(fn ($q) => $q->where('authenticatable_type', 'App\Core\Models\User')->where('authenticatable_id', $owner))
    ->orWhere(fn ($q) => $q->where('authenticatable_type', 'App\Platform\Models\PlatformUser')->where('authenticatable_id', $viewer))->delete();
echo json_encode(['member842' => $uid, 'pwd_user_id' => $pwd, 'twofa_user_id' => $twofa, 'twofa_operator_id' => $op])."\n";
