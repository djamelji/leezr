<?php

/**
 * ADR-814 — removal of the fictitious company 102 (« E2E Recette 813 667092 », created by the ADR-813
 * signup regression) BY EXACT IDENTIFIERS ONLY. Nothing else is selected by pattern or prefix.
 *
 *   php docs/bmad/adr814/purge-company-102.php check                 READ-ONLY : re-verifies every guard
 *   php docs/bmad/adr814/purge-company-102.php purge <backup.json> [--execute]
 *       Backs up every row first (full columns), then deletes them in one transaction. Dry-run unless --execute.
 *   php docs/bmad/adr814/purge-company-102.php restore <backup.json> [--execute]
 *       Re-inserts the backed-up rows (parents first). Dry-run unless --execute.
 */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const CID = 102;
const UID = 179;
const NAME = 'E2E Recette 813 667092';
const EMAIL = 'e2e.813.owner.667092@leezr.invalid';

// Exact rows, inventoried 2026-11-17 (ADR-814 §2). Order = deletion order (children first).
$PLAN = [
    ['company_role_permission', 'company_role_id', [175, 176, 177, 178, 179]],
    ['company_dashboard_layouts', 'id', [184, 185, 186, 187, 188]],
    ['memberships', 'id', [191]],
    ['sessions', 'user_id', [UID]],
    ['company_roles', 'id', [175, 176, 177, 178, 179]],
    ['company_module_activation_reasons', 'id', [371, 372, 373, 374, 375, 376]],
    ['company_modules', 'id', [332, 333, 334, 335, 336, 337]],
    ['company_preset_snapshots', 'id', [20]],
    ['document_type_activations', 'id', [169, 170, 171, 172, 173]],
    ['field_activations', 'id', range(1104, 1139)],
    ['field_values', 'id', null],   // resolved below : model_type Company + model_id 102 (exact ids listed in the backup)
    ['company_jobdomain', 'company_id', [CID]],
    ['company_audit_logs', 'id', ['01m7ketw3nvkedfa7nc88gh315']],
    ['registration_funnel_events', 'id', [7]],
    ['subscriptions', 'id', [53]],
    ['companies', 'id', [CID]],
    ['users', 'id', [UID]],
];
$EXPECTED = ['company_role_permission' => 101, 'company_dashboard_layouts' => 5, 'memberships' => 1, 'sessions' => null, 'company_roles' => 5,
    'company_module_activation_reasons' => 6, 'company_modules' => 6, 'company_preset_snapshots' => 1, 'document_type_activations' => 5,
    'field_activations' => 36, 'field_values' => 7, 'company_jobdomain' => 1, 'company_audit_logs' => 1, 'registration_funnel_events' => 1,
    'subscriptions' => 1, 'companies' => 1, 'users' => 1];

$fvIds = DB::table('field_values')->where('model_type', 'like', '%Company')->where('model_id', CID)->orderBy('id')->pluck('id')->all();
foreach ($PLAN as &$s) if ($s[0] === 'field_values') $s[2] = $fvIds; unset($s);

function guards(): array
{
    $f = [];
    $c = DB::table('companies')->find(CID);
    if (! $c || $c->name !== NAME) $f[] = 'company 102 identity mismatch';
    if (DB::table('users')->where('id', UID)->value('email') !== EMAIL) $f[] = 'owner identity mismatch';
    if (DB::table('memberships')->where('user_id', UID)->where('company_id', '!=', CID)->exists()) $f[] = 'owner belongs to another company';
    if (DB::table('memberships')->where('company_id', CID)->where('user_id', '!=', UID)->exists()) $f[] = 'company has other members';
    $tables = array_map(fn ($t) => array_values((array) $t)[0], DB::select('show tables'));
    foreach ($tables as $t) {
        if (preg_match('/invoice|payment|credit|ledger|wallet|refund|transaction|dunning|addon_subscription/i', $t)
            && Schema::hasColumn($t, 'company_id') && DB::table($t)->where('company_id', CID)->exists()) $f[] = "financial rows in {$t}";
    }
    $sub = DB::table('subscriptions')->where('company_id', CID)->get();
    if ($sub->count() !== 1 || $sub[0]->id !== 53 || $sub[0]->plan_price_cents !== 0 || $sub[0]->provider !== 'internal' || $sub[0]->provider_subscription_id) $f[] = 'subscription is not the free internal 53';
    foreach ($tables as $t) if (Schema::hasColumn($t, 'subscription_id') && DB::table($t)->where('subscription_id', 53)->exists()) $f[] = "subscription 53 referenced by {$t}";
    if (DB::table('jobs')->where('payload', 'like', '%tenantCompanyId";i:'.CID.';%')->orWhere('payload', 'like', '%"id";a:1:{i:0;i:'.UID.';}%')->exists()) $f[] = 'a pending job references 102/179';
    if (DB::table('email_logs')->where('company_id', CID)->orWhere('recipient_email', EMAIL)->exists()) $f[] = 'e-mail logs exist';
    if (DB::table('platform_audit_logs')->where('target_type', 'like', '%ompan%')->where('target_id', (string) CID)->exists()) $f[] = 'platform audit references 102';
    foreach ($tables as $t) { // any company_id row NOT in the plan
        if (! Schema::hasColumn($t, 'company_id')) continue;
        $n = DB::table($t)->where('company_id', CID)->count();
        if ($n && ! in_array($t, ['company_audit_logs', 'company_dashboard_layouts', 'company_jobdomain', 'company_module_activation_reasons', 'company_modules',
            'company_preset_snapshots', 'company_roles', 'document_type_activations', 'field_activations', 'memberships', 'registration_funnel_events', 'subscriptions'], true)) $f[] = "unplanned rows in {$t} ({$n})";
    }

    return $f;
}

$mode = $argv[1] ?? 'check';
if ($mode === 'check' || $mode === 'purge') {
    $fail = guards();
    $counts = [];
    foreach ($PLAN as [$t, $col, $ids]) $counts[$t] = DB::table($t)->whereIn($col, $ids)->count();
    foreach ($EXPECTED as $t => $n) if ($n !== null && $counts[$t] !== $n) $fail[] = "{$t}: {$counts[$t]} rows, expected {$n}";
    echo json_encode($counts), "\n";
    if ($fail) { echo "STOP\n".implode("\n", $fail)."\n"; exit(1); }
    echo "Guards OK\n";
    if ($mode === 'check') exit(0);

    $backup = ['taken_at' => now()->toIso8601String(), 'company' => CID, 'tables' => []];
    foreach ($PLAN as [$t, $col, $ids]) $backup['tables'][$t] = DB::table($t)->whereIn($col, $ids)->get()->map(fn ($r) => (array) $r)->all();
    file_put_contents($argv[2], json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'Backup : '.$argv[2].' sha256 '.hash_file('sha256', $argv[2])."\n";
    if (! in_array('--execute', $argv, true)) { echo "Dry-run : nothing deleted.\n"; exit(0); }
    DB::transaction(function () use ($PLAN) {
        foreach ($PLAN as [$t, $col, $ids]) echo "DELETE {$t} : ".DB::table($t)->whereIn($col, $ids)->delete()."\n";
    });
    exit(0);
}
if ($mode === 'restore') {
    $b = json_decode(file_get_contents($argv[2]), true); $exec = in_array('--execute', $argv, true);
    $order = array_reverse(array_keys($b['tables']));
    if ($exec) DB::transaction(function () use ($b, $order) { foreach ($order as $t) foreach (array_chunk($b['tables'][$t], 100) as $c) DB::table($t)->insert($c); });
    foreach ($order as $t) echo ($exec ? 'RESTORED ' : 'WOULD RESTORE ')."{$t} : ".count($b['tables'][$t])."\n";
    exit(0);
}
echo "Unknown mode\n"; exit(1);
