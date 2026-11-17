<?php

/**
 * ADR-814 — FUNCTIONAL reset of the acceptance company 101 (« E2E Recette 8091 433828 »). PREPARED, NOT RUN.
 * Brings 101 back to « configured company with its people » : the operations done during an acceptance
 * run are removed ; identity, people, contracts, configuration, history (audit, e-mails, notifications)
 * and EVERY billing / accounting record are kept untouched.
 *
 *   php docs/bmad/adr814/reset-company-101.php plan                 READ-ONLY : exact ids per table, kept tables
 *   php docs/bmad/adr814/reset-company-101.php reset <backup-dir> [--execute]
 *       Snapshot of the plan + full rows + stored files COPIED into <backup-dir> (outside git, e.g.
 *       storage/app/private/adr814/reset-101-<date>), then one transaction ; leave balances re-healed by the
 *       leave domain (LeaveLedger::computeBalance + LeaveBalanceCacheHealer, as e2e:reset-leave). Dry-run unless --execute.
 *   php docs/bmad/adr814/reset-company-101.php restore <backup-dir> [--execute]
 */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const CID = 101;
const NAME = 'E2E Recette 8091 433828';

// Deletion order (children first). Selection = company_id 101 ; leave ledger = only entries of the deleted requests.
// FK cascades are covered explicitly (breaks → time entries, scheduled activities → shifts) so the backup is complete.
const RESET = [
    'workforce_planning_notification_outbox', 'workforce_planning_operations', 'workforce_time_entry_breaks', 'workforce_time_entries',
    'workforce_scheduled_activities', 'workforce_shifts',
    'workforce_leave_ledger_entries', 'workforce_leave_requests',
    'document_request_events', 'document_requests', 'member_documents',
    'shipments',
];
// Never touched, whatever happens (asserted before and after).
const FINANCIAL = ['invoices', 'financial_ledger_entries', 'company_wallets', 'company_addon_subscriptions', 'subscriptions', 'company_sequences'];

function selection(): array
{
    $sel = [];
    $requests = DB::table('workforce_leave_requests')->where('company_id', CID)->pluck('id')->all();
    foreach (RESET as $t) {
        $q = DB::table($t)->where('company_id', CID);
        if ($t === 'workforce_leave_ledger_entries') $q->where('reference_type', 'leave_request')->whereIn('reference_id', $requests ?: [0]);
        $sel[$t] = $q->orderBy('id')->pluck('id')->all();
    }

    return $sel;
}

function financialFingerprint(): string
{
    $f = [];
    foreach (FINANCIAL as $t) $f[$t] = DB::table($t)->where('company_id', CID)->orderBy('id')->get()->toJson();
    $f['invoice_lines'] = DB::table('invoice_lines')->whereIn('invoice_id', DB::table('invoices')->where('company_id', CID)->pluck('id'))->orderBy('id')->get()->toJson();

    return hash('sha256', json_encode($f));
}

if (DB::table('companies')->where('id', CID)->value('name') !== NAME) { echo "STOP : company 101 identity mismatch\n"; exit(1); }
$mode = $argv[1] ?? 'plan';
$sel = selection();
// Guard : a kept table must never be modified by a SET NULL / cascade.
if (DB::table('generated_documents')->whereIn('member_document_id', $sel['member_documents'] ?: [0])->exists()) { echo "STOP : generated_documents reference documents to reset\n"; exit(1); }
foreach (['workforce_time_entry_breaks' => ['time_entry_id', 'workforce_time_entries'], 'workforce_scheduled_activities' => ['shift_id', 'workforce_shifts']] as $child => [$fk, $parent]) {
    if (DB::table($child)->whereIn($fk, $sel[$parent] ?: [0])->where('company_id', '!=', CID)->exists()) { echo "STOP : {$child} of another company\n"; exit(1); }
}
$kept = [];
foreach (array_map(fn ($t) => array_values((array) $t)[0], DB::select('show tables')) as $t) {
    if (! in_array($t, RESET, true) && Schema::hasColumn($t, 'company_id') && ($n = DB::table($t)->where('company_id', CID)->count())) $kept[$t] = $n;
}

if ($mode === 'plan') {
    echo json_encode(['reset' => $sel, 'kept' => $kept, 'financial_fingerprint' => financialFingerprint(),
        'files' => DB::table('member_documents')->whereIn('id', $sel['member_documents'] ?: [0])->pluck('file_path')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($mode === 'reset') {
    $dir = rtrim($argv[2] ?? '', '/'); if (! $dir) { echo "backup dir required\n"; exit(1); }
    $exec = in_array('--execute', $argv, true);
    $before = financialFingerprint();
    @mkdir("{$dir}/files", 0700, true);
    $rows = [];
    foreach ($sel as $t => $ids) $rows[$t] = DB::table($t)->whereIn('id', $ids ?: [0])->get()->map(fn ($r) => (array) $r)->all();
    $files = DB::table('member_documents')->whereIn('id', $sel['member_documents'] ?: [0])->pluck('file_path')->filter()->all();
    foreach ($files as $p) if (Storage::exists($p)) { @mkdir(dirname("{$dir}/files/{$p}"), 0700, true); copy(Storage::path($p), "{$dir}/files/{$p}"); }
    $affected = collect($rows['workforce_leave_requests'])->map(fn ($r) => [$r['employee_id'], $r['leave_type_id']])->unique()->values()->all();
    file_put_contents("{$dir}/backup.json", json_encode(['taken_at' => now()->toIso8601String(), 'financial_fingerprint' => $before, 'rows' => $rows, 'files' => $files, 'leave_heal' => $affected], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Backup : {$dir}/backup.json sha256 ".hash_file('sha256', "{$dir}/backup.json")."\n";
    foreach ($sel as $t => $ids) echo ($exec ? 'DELETE ' : 'WOULD DELETE ')."{$t} : ".count($ids)."\n";
    if (! $exec) { echo "Dry-run : nothing changed.\n"; exit(0); }
    DB::transaction(function () use ($sel) { foreach ($sel as $t => $ids) if ($ids) DB::table($t)->whereIn('id', $ids)->delete(); });
    foreach ($files as $p) Storage::delete($p);
    foreach ($affected as [$emp, $type]) {
        $year = (int) now()->year;
        \App\Core\Workforce\Services\LeaveBalanceCacheHealer::healIfNeeded(CID, $emp, $type, $year, \App\Core\Workforce\Services\LeaveLedger::computeBalance(CID, $emp, $type, $year));
    }
    if (financialFingerprint() !== $before) { echo "ALERT : financial fingerprint changed\n"; exit(1); }
    echo "Done ; financial records identical.\n"; exit(0);
}
if ($mode === 'restore') {
    $dir = rtrim($argv[2] ?? '', '/'); $b = json_decode(file_get_contents("{$dir}/backup.json"), true); $exec = in_array('--execute', $argv, true);
    foreach (array_reverse($b['rows'], true) as $t => $rs) echo ($exec ? 'RESTORE ' : 'WOULD RESTORE ')."{$t} : ".count($rs)."\n";
    if (! $exec) { echo "Dry-run.\n"; exit(0); }
    DB::transaction(function () use ($b) { foreach (array_reverse($b['rows'], true) as $t => $rs) foreach (array_chunk($rs, 100) as $c) DB::table($t)->insert($c); });
    foreach ($b['files'] as $p) if (is_file("{$dir}/files/{$p}")) Storage::put($p, file_get_contents("{$dir}/files/{$p}"));
    foreach ($b['leave_heal'] as [$emp, $type]) {
        $year = (int) now()->year;
        \App\Core\Workforce\Services\LeaveBalanceCacheHealer::healIfNeeded(CID, $emp, $type, $year, \App\Core\Workforce\Services\LeaveLedger::computeBalance(CID, $emp, $type, $year));
    }
    echo "Restored.\n"; exit(0);
}
echo "Unknown mode\n"; exit(1);
