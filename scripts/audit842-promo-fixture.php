<?php
// Leezr 4.2 ADR-851 recipe fixture — MARKED audit clone only. Two fictitious promo codes (upserted, reset each run) :
// M851VALIDE (20 %, Pro, 3 months) and M851EXPIRE (expired yesterday). Touches no other coupon, no subscription.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$base = ['type' => 'percentage', 'value' => 2000, 'is_active' => true, 'duration_months' => 3, 'max_uses' => null, 'used_count' => 0, 'max_uses_per_company' => 1,
    'applicable_plan_keys' => json_encode(['pro']), 'applicable_billing_cycles' => null, 'first_purchase_only' => false, 'starts_at' => null, 'updated_at' => now()];
DB::table('billing_coupons')->updateOrInsert(['code' => 'M851VALIDE'], $base + ['name' => 'Recette ADR-851 (valide)', 'expires_at' => null, 'created_at' => now()]);
DB::table('billing_coupons')->updateOrInsert(['code' => 'M851EXPIRE'], $base + ['name' => 'Recette ADR-851 (expiré)', 'expires_at' => now()->subDay(), 'created_at' => now()]);
echo json_encode(DB::table('billing_coupons')->whereIn('code', ['M851VALIDE', 'M851EXPIRE'])->pluck('id', 'code'))."\n";
