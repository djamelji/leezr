<?php
// Leezr 4.2 M8 recipe fixture — MARKED audit clone only, fictitious company 106 « Audit 842 Services » only.
//   reset  : no scheduled cancellation on subscription 106, previous recipe exports (rows + archives) removed.
//   m81 <password-file> : ADR-846 recipe — platform permission catalog synced, two operators (opa846 / opb846) holding
//            `exceptional_company_export`, one recipe document whose FILE is missing (→ partial export).
//   suspend : company 106 suspended (reset puts it back to active).
//   expire : the latest ready export of 106 is moved past its expiry date (simulates the 7-day TTL) and the
//            exports are aged past the request cooldown so a new one can be requested in the same recipe.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$c = 106;
if (! DB::table('companies')->where('id', $c)->where('name', 'like', 'Audit 842 Services%')->exists()) { fwrite(STDERR, "fictitious company 106 not found\n"); exit(2); }
$mode = $argv[1] ?? 'reset';
if ($mode === 'suspend') {
    DB::table('companies')->where('id', $c)->update(['status' => 'suspended']);
    echo "suspended\n";
    exit(0);
}
if ($mode === 'm81') {
    App\Platform\RBAC\PlatformPermissionCatalog::sync();
    $hash = Illuminate\Support\Facades\Hash::make(trim(file_get_contents($argv[2])));
    DB::table('platform_roles')->where('key', 'recette846_exceptional')->update(['key' => 'rec846_export']); // key ≤ 20 chars : copied into platform_audit_logs.actor_type (latent limit, see ADR-846)
    $role = DB::table('platform_roles')->where('key', 'rec846_export')->value('id')
        ?? DB::table('platform_roles')->insertGetId(['key' => 'rec846_export', 'name' => 'Export exceptionnel (recette)', 'created_at' => now(), 'updated_at' => now()]);
    $perms = DB::table('platform_permissions')->whereIn('key', ['view_companies', 'exceptional_company_export'])->pluck('id');
    DB::table('platform_role_permission')->where('platform_role_id', $role)->delete();
    foreach ($perms as $p) DB::table('platform_role_permission')->insert(['platform_role_id' => $role, 'platform_permission_id' => $p]);
    foreach (['opa846' => 'A', 'opb846' => 'B'] as $login => $tag) {
        $email = "$login@platform.test";
        $id = DB::table('platform_users')->where('email', $email)->value('id');
        if ($id) DB::table('platform_users')->where('id', $id)->update(['password' => $hash, 'updated_at' => now()]);
        else $id = DB::table('platform_users')->insertGetId(['first_name' => 'Opérateur', 'last_name' => "Recette $tag", 'email' => $email, 'password' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_role_user')->updateOrInsert(['platform_user_id' => $id, 'platform_role_id' => $role], ['created_at' => now(), 'updated_at' => now()]);
        DB::table('two_factor_credentials')->where('authenticatable_type', 'App\Platform\Models\PlatformUser')->where('authenticatable_id', $id)->delete();
    }
    $type = DB::table('document_types')->value('id');
    $uploader = DB::table('memberships')->where('company_id', $c)->where('role', 'owner')->value('user_id');
    DB::table('company_documents')->where('company_id', $c)->where('file_name', 'recette846-absent.pdf')->delete();
    DB::table('company_documents')->insert(['company_id' => $c, 'document_type_id' => $type, 'file_path' => "company-documents/$c/recette846-absent.pdf", 'file_name' => 'recette846-absent.pdf',
        'file_size_bytes' => 1000, 'mime_type' => 'application/pdf', 'uploaded_by' => $uploader, 'created_at' => now(), 'updated_at' => now()]);
    echo "m81 ready\n";
    exit(0);
}
if ($mode === 'expire') {
    $n = DB::table('company_data_exports')->where('company_id', $c)->where('status', 'ready')->orderByDesc('id')->limit(1)->update(['expires_at' => now()->subMinute()]);
    DB::table('company_data_exports')->where('company_id', $c)->update(['created_at' => now()->subHour()]); // past the request cooldown
    echo "expired: $n\n";
    exit(0);
}
foreach (DB::table('company_data_exports')->where('company_id', $c)->get() as $e) {
    if ($e->path && str_starts_with($e->path, "company-exports/$c/")) Storage::disk($e->disk)->delete($e->path);
}
DB::table('company_data_exports')->where('company_id', $c)->delete();
DB::table('subscriptions')->where('company_id', $c)->where('is_current', 1)->update(['cancel_at_period_end' => false]);
DB::table('companies')->where('id', $c)->update(['status' => 'active']);
DB::table('company_documents')->where('company_id', $c)->where('file_name', 'recette846-absent.pdf')->delete(); // ADR-846 recipe row
// request / download limiters of the recipe owner (clone cache prefix) — so the recipe can be replayed
$owner = DB::table('memberships')->where('company_id', $c)->where('role', 'owner')->value('user_id');
foreach (['company-data-export', 'company-data-export-download'] as $prefix) Illuminate\Support\Facades\RateLimiter::clear($prefix.sha1($owner));
echo "reset ok\n";
