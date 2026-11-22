<?php
// Leezr 5.0 L1 (ADR-855) cockpit recipe fixture — MARKED audit clone only. Two fictitious Platform operators WITH
// cockpit access but WITHOUT finance : « Support cockpit » (view_dashboard, manage_support, view_companies) and
// « Gouvernance cockpit » (view_dashboard, manage_modules, manage_jobdomains). The Support layout is seeded with
// finance widgets on purpose : the cockpit must never serve them (ADR-855 layout filtering).
//   php scripts/audit842-cockpit855-fixture.php <password-file>
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$hash = Hash::make(trim(file_get_contents($argv[1])));
$now = now();
$out = [];
foreach ([
    'support-cockpit855@platform.test' => ['Support', 'Cockpit', 'audit855_support', 'Audit 855 — Support cockpit', ['view_dashboard', 'manage_support', 'view_companies']],
    'gov-cockpit855@platform.test' => ['Gouvernance', 'Cockpit', 'audit855_gov', 'Audit 855 — Gouvernance cockpit', ['view_dashboard', 'manage_modules', 'manage_jobdomains']],
] as $email => [$first, $last, $roleKey, $roleName, $perms]) {
    $role = DB::table('platform_roles')->where('key', $roleKey)->value('id')
        ?? DB::table('platform_roles')->insertGetId(['key' => $roleKey, 'name' => $roleName, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('platform_role_permission')->where('platform_role_id', $role)->delete();
    foreach (DB::table('platform_permissions')->whereIn('key', $perms)->pluck('id') as $pid) {
        DB::table('platform_role_permission')->insert(['platform_role_id' => $role, 'platform_permission_id' => $pid]);
    }
    $uid = DB::table('platform_users')->where('email', $email)->value('id')
        ?? DB::table('platform_users')->insertGetId(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'password' => $hash, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('platform_users')->where('id', $uid)->update(['password' => $hash]);
    DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $uid, 'platform_role_id' => $role]);
    $out[$email] = $uid;
}
// Support's saved layout deliberately contains finance widgets (must be filtered on read)
$t = fn ($k, $x, $y, $w, $h) => ['key' => $k, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'scope' => 'global', 'config' => []];
DB::table('platform_user_dashboard_layouts')->updateOrInsert(['user_id' => $out['support-cockpit855@platform.test']], ['layout_json' => json_encode([
    $t('billing.revenue_mtd', 0, 0, 3, 2), $t('platform.commercial_overview', 3, 0, 9, 5), $t('platform.support_overview', 0, 5, 3, 2), $t('platform.trials_ending', 3, 5, 4, 4),
]), 'created_at' => $now, 'updated_at' => $now]);
DB::table('platform_user_dashboard_layouts')->where('user_id', $out['gov-cockpit855@platform.test'])->delete();
echo json_encode($out), "\n";
