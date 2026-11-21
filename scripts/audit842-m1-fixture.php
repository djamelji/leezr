<?php
// Leezr 4.2 M1 recipe fixture — MARKED audit clone only. Fictitious company 106 (created by the audit) gets an ANNUAL
// Pro subscription ; a read-only Platform operator (view_companies) is created. Password read from a file, never printed.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$company = DB::table('companies')->where('id', 106)->where('name', 'like', 'Audit 842 Services%')->first();
if (! $company) { fwrite(STDERR, "fictitious company 106 not found\n"); exit(2); }
DB::table('subscriptions')->where('company_id', 106)->whereIn('status', ['active', 'trialing'])
    ->update(['plan_key' => 'pro', 'interval' => 'yearly', 'current_period_start' => '2026-11-21 00:00:00', 'current_period_end' => '2027-11-20 23:59:59', 'updated_at' => now()]);
DB::table('companies')->where('id', 106)->update(['plan_key' => 'pro']);
$perm = DB::table('platform_permissions')->where('key', 'view_companies')->value('id');
$role = DB::table('platform_roles')->where('key', 'audit842_viewer')->value('id') ?? DB::table('platform_roles')->insertGetId(['key' => 'audit842_viewer', 'name' => 'Audit 842 — lecture sociétés', 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_permission')->updateOrInsert(['platform_role_id' => $role, 'platform_permission_id' => $perm]);
$u = DB::table('platform_users')->where('email', 'viewer842@platform.test')->value('id') ?? DB::table('platform_users')->insertGetId(['first_name' => 'Lecteur', 'last_name' => 'Audit', 'email' => 'viewer842@platform.test', 'password' => Hash::make(trim(file_get_contents($argv[1]))), 'created_at' => now(), 'updated_at' => now()]);
DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $u, 'platform_role_id' => $role]);
echo "company 106 → pro yearly ; viewer842 ready\n";
