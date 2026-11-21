<?php
// Leezr 4.2 M8 recipe fixture — MARKED audit clone only, fictitious company 106 « Audit 842 Services » only.
//   reset  : no scheduled cancellation on subscription 106, previous recipe exports (rows + archives) removed.
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
// request / download limiters of the recipe owner (clone cache prefix) — so the recipe can be replayed
$owner = DB::table('memberships')->where('company_id', $c)->where('role', 'owner')->value('user_id');
foreach (['company-data-export', 'company-data-export-download'] as $prefix) Illuminate\Support\Facades\RateLimiter::clear($prefix.sha1($owner));
echo "reset ok\n";
