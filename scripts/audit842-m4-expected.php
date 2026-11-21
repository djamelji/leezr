<?php
// ADR-841 (M4) — READ ONLY. Expected financial figures computed INDEPENDENTLY of the application code (raw SQL),
// to be compared with what the Platform screens display. JSON on stdout. No write.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$now = now();
$subs = DB::select("SELECT s.company_id, s.`interval`, s.plan_price_cents, s.coupon_id, s.status FROM subscriptions s WHERE s.is_current = 1 AND s.status IN ('active','past_due')");
$mrr = 0; $addonsTotal = 0; $per = [];
foreach ($subs as $s) {
    $plan = (int) $s->plan_price_cents;
    $planMonthly = $s->interval === 'yearly' ? (int) round($plan / 12) : $plan;
    $addons = 0;
    foreach (DB::select("SELECT `interval`, amount_cents FROM company_addon_subscriptions WHERE company_id = ? AND (deactivated_at IS NULL OR deactivated_at > ?)", [$s->company_id, $now]) as $a) {
        $addons += $a->interval === 'yearly' ? (int) round($a->amount_cents / 12) : (int) $a->amount_cents;
    }
    if ($s->coupon_id) { fwrite(STDERR, "coupon present on company {$s->company_id} : not modelled by this script\n"); }
    $per[$s->company_id] = $planMonthly + $addons;
    $mrr += $planMonthly + $addons; $addonsTotal += $addons;
}
$revenueMonth = (int) DB::scalar("SELECT COALESCE(SUM(credit),0) FROM financial_ledger_entries WHERE entry_type='invoice_issued' AND account_code='REVENUE' AND recorded_at BETWEEN ? AND ?", [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
$trials = (int) DB::scalar("SELECT COUNT(*) FROM subscriptions WHERE is_current = 1 AND status = 'trialing'");
echo json_encode(['database' => DB::connection()->getDatabaseName(), 'mrr_cents' => $mrr, 'arr_cents' => $mrr * 12, 'addons_cents' => $addonsTotal,
    'revenue_month_cents' => $revenueMonth, 'trialing' => $trials, 'per_company' => $per], JSON_PRETTY_PRINT)."\n";
