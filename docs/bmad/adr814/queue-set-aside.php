<?php

/**
 * ADR-814 — DEV queue : per-job classification, traceable set-aside, restore. NEVER runs a job.
 *
 *   php docs/bmad/adr814/queue-set-aside.php classify <out.json>
 *       READ-ONLY. One record per pending job : type, owning company (and how it is proven), target
 *       still present, categories, verdict, sha256 of the payload.
 *   php docs/bmad/adr814/queue-set-aside.php backup <out.json> <id,id,...>
 *       READ-ONLY. Full rows (all columns, payload verbatim) + sha256, to restore them exactly.
 *   php docs/bmad/adr814/queue-set-aside.php park <id,id,...> [--execute]
 *       Moves the listed jobs to the queue `parked-adr814` (no deletion ; no worker listens to it).
 *       REFUSES any job whose verdict is not `set_aside` (owner proven to be a fixture company AND
 *       target gone), any reserved job, and job 98. Dry-run unless --execute.
 *   php docs/bmad/adr814/queue-set-aside.php restore <backup.json> [--execute]
 *       Puts each backed-up row back exactly (queue, payload, attempts, dates) after checking its
 *       sha256 ; re-inserts a row that was deleted. Dry-run unless --execute.
 */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const FIXTURE_COMPANIES = [101, 102];   // fictitious companies created by acceptance runs (ADR-809.1, ADR-813)
const EXCLUDED = [98];                  // ADR-814 : AI job 98 excluded from any execution or move
const PARK_QUEUE = 'parked-adr814';

$int = fn (string $cmd, string $prop) => preg_match('/'.preg_quote($prop, '/').'";i:(\d+)/', $cmd, $m) ? (int) $m[1] : null;
$user = fn (string $cmd) => preg_match('/App\\\\Core\\\\Models\\\\User";s:2:"id";a:1:\{i:0;i:(\d+);/', $cmd, $m) ? (int) $m[1] : null;
$companiesOf = fn (?int $uid) => $uid ? DB::table('memberships')->where('user_id', $uid)->orderBy('company_id')->pluck('company_id')->all() : [];

function classify(callable $int, callable $user, callable $companiesOf): array
{
    $out = [];
    foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
        $p = json_decode($j->payload, true);
        $cmd = $p['data']['command'];
        $type = class_basename($p['displayName']);
        $r = ['id' => $j->id, 'queue' => $j->queue, 'type' => $type, 'created' => date('Y-m-d H:i', $j->created_at),
            'attempts' => $j->attempts, 'reserved' => (bool) $j->reserved_at, 'company_id' => null, 'company_proof' => null,
            'target' => null, 'target_exists' => null, 'recipient' => null, 'cat' => [], 'sha256' => hash('sha256', $j->payload)];

        switch ($type) {
            case 'SendMembershipInvitationJob':
                $r['company_id'] = $int($cmd, 'tenantCompanyId'); $r['company_proof'] = 'payload tenantCompanyId';
                $mid = $int($cmd, 'membershipId'); $r['target'] = "membership #{$mid}";
                $m = DB::table('memberships')->find($mid); $r['target_exists'] = (bool) $m;
                $r['recipient'] = $m ? DB::table('users')->where('id', $m->user_id)->value('email') : null;
                break;
            case 'ProcessDocumentAiJob':
                $id = $int($cmd, 'documentId');
                $cls = preg_match('/documentClass";s:\d+:"([^"]+)"/', $cmd, $m) ? class_basename($m[1]) : '?';
                $table = $cls === 'CompanyDocument' ? 'company_documents' : 'member_documents';
                $r['target'] = "{$cls} #{$id}";
                $doc = DB::table($table)->find($id); $r['target_exists'] = (bool) $doc;
                if ($doc) { $r['company_id'] = $doc->company_id; $r['company_proof'] = "{$table}.company_id"; }
                else {
                    $a = DB::table('company_audit_logs')->where('target_type', $cls === 'CompanyDocument' ? 'company_document' : 'member_document')
                        ->where('target_id', (string) $id)->value('company_id');
                    if ($a) { $r['company_id'] = $a; $r['company_proof'] = 'company_audit_logs (document withdrawn)'; }
                }
                if ($doc) $r['cat'][] = 'billable';   // calls the AI provider (cost) only when the document exists
                break;
            case 'ScanOnboardingUploadJob':
                $r['company_id'] = $int($cmd, 'tenantCompanyId'); $r['company_proof'] = 'payload tenantCompanyId';
                $id = $int($cmd, 'uploadId'); $r['target'] = "onboarding_upload #{$id}";
                $r['target_exists'] = DB::table('onboarding_uploads')->where('id', $id)->exists();
                break;
            default: // queued notifications
                $uid = $user($cmd); $r['recipient'] = $uid ? DB::table('users')->where('id', $uid)->value('email') : null;
                $notif = preg_match('/"notification";O:\d+:"([^"]+)"/', $cmd, $m) ? class_basename($m[1]) : $type;
                $r['type'] = $notif;
                if ($notif === 'InvoiceCreated') {
                    $iid = preg_match('/Invoice";s:2:"id";i:(\d+);/', $cmd, $m) ? (int) $m[1] : null;
                    $inv = $iid ? DB::table('invoices')->find($iid) : null;
                    $r['target'] = "invoice #{$iid}"; $r['target_exists'] = (bool) $inv;
                    if ($inv) { $r['company_id'] = $inv->company_id; $r['company_proof'] = 'invoices.company_id'; }
                    else {
                        $log = ($l = $int($cmd, 'emailLogId')) ? DB::table('email_logs')->find($l) : null;
                        if ($log?->company_id) { $r['company_id'] = $log->company_id; $r['company_proof'] = 'email_logs.company_id'; }
                    }
                } elseif ($notif === 'PlanningPublishedNotification') {
                    $period = preg_match('/periodLabel";s:\d+:"([^"]+)"/', $cmd, $m) ? $m[1] : '';
                    $r['target'] = "planning {$period}"; $r['target_exists'] = true;
                    $last = preg_match_all('/\d{4}-\d{2}-\d{2}/', $period, $d) ? max($d[0]) : null;
                    if ($last && $last < now()->toDateString()) $r['cat'][] = 'stale';
                    $cs = $companiesOf($uid);
                    if (count($cs) === 1) { $r['company_id'] = $cs[0]; $r['company_proof'] = 'recipient has a single company'; }
                } elseif ($notif === 'CompanyInvitationNotification') {
                    $name = preg_match('/companyName";s:\d+:"([^"]+)"/', $cmd, $m) ? $m[1] : null;
                    $cid = $name ? DB::table('companies')->where('name', $name)->value('id') : null;
                    $r['company_id'] = $cid; $r['company_proof'] = 'payload companyName';
                    $r['target'] = "invitation of {$r['recipient']} to {$name}";
                    $r['target_exists'] = true;  // needs no model : it WOULD be sent
                    if (! ($cid && in_array($cid, $companiesOf($uid), true))) $r['cat'][] = 'stale';   // recipient no longer a member : misleading e-mail
                }
                $r['cat'][] = 'external_notification';
        }

        $r['will_fail'] = $r['target_exists'] === false;   // its model is gone : the job can only fail
        if ($r['will_fail']) $r['cat'][] = 'fixture_deleted';
        if ($r['company_id'] === null) $r['cat'][] = 'company_unknown';
        if ($r['target_exists'] === true && ! in_array('stale', $r['cat'], true)) $r['cat'][] = 'useful';
        if ($type === 'SendMembershipInvitationJob' && $r['target_exists']) $r['cat'][] = 'external_notification';
        $r['cat'] = array_values(array_unique($r['cat']));
        $fixture = in_array($r['company_id'], FIXTURE_COMPANIES, true);
        $r['verdict'] = match (true) {
            in_array($j->id, EXCLUDED, true) => 'excluded (ADR-814)',
            $r['target_exists'] === false && $fixture => 'set_aside',
            $r['target_exists'] === false && $r['company_id'] === null => 'doomed — owner unproven : kept, decision required',
            $r['target_exists'] === false => 'doomed — owned by company '.$r['company_id'].' (not a fixture) : kept, decision required',
            default => 'kept',
        };
        $out[] = $r;
    }

    return $out;
}

$mode = $argv[1] ?? 'classify';
$ids = fn (?string $s) => array_values(array_filter(array_map('intval', explode(',', (string) $s))));

if ($mode === 'classify') {
    $rows = classify($int, $user, $companiesOf);
    file_put_contents($argv[2] ?? 'php://stdout', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach (collect($rows)->groupBy('verdict') as $v => $g) echo str_pad((string) count($g), 3, ' ', STR_PAD_LEFT), "  {$v} : ", $g->pluck('id')->implode(','), "\n";
    exit(0);
}
if ($mode === 'backup') {
    $want = $ids($argv[3] ?? '');
    $rows = DB::table('jobs')->whereIn('id', $want)->orderBy('id')->get()->map(fn ($r) => (array) $r + ['sha256' => hash('sha256', $r->payload)])->all();
    if (count($rows) !== count($want)) { echo "REFUSED : ".count($want) - count($rows)." id(s) not pending\n"; exit(1); }
    file_put_contents($argv[2], json_encode(['taken_at' => now()->toIso8601String(), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo count($rows), " rows backed up\n"; exit(0);
}
if ($mode === 'park') {
    $want = $ids($argv[2] ?? ''); $exec = in_array('--execute', $argv, true);
    $by = collect(classify($int, $user, $companiesOf))->keyBy('id'); $bad = [];
    foreach ($want as $id) {
        $r = $by[$id] ?? null;
        if (! $r) $bad[] = "{$id}: not pending";
        elseif ($r['verdict'] !== 'set_aside') $bad[] = "{$id}: {$r['verdict']}";
        elseif ($r['reserved']) $bad[] = "{$id}: reserved by a worker";
    }
    if (! $want || $bad) { echo "REFUSED\n".implode("\n", $bad)."\n"; exit(1); }
    foreach ($want as $id) echo ($exec ? 'PARK ' : 'WOULD PARK ')."{$id} → ".PARK_QUEUE."\n";
    if ($exec) DB::table('jobs')->whereIn('id', $want)->whereNull('reserved_at')->update(['queue' => PARK_QUEUE]);
    echo $exec ? "Done.\n" : "Dry-run : nothing changed.\n"; exit(0);
}
if ($mode === 'restore') {
    $b = json_decode(file_get_contents($argv[2]), true); $exec = in_array('--execute', $argv, true);
    foreach ($b['rows'] as $row) {
        $sha = $row['sha256']; unset($row['sha256']);
        if (hash('sha256', $row['payload']) !== $sha) { echo "REFUSED {$row['id']}: payload does not match its sha256\n"; exit(1); }
        $cur = DB::table('jobs')->find($row['id']);
        if ($cur && $cur->payload !== $row['payload']) { echo "REFUSED {$row['id']}: another job now uses this id\n"; exit(1); }
        echo ($exec ? '' : 'WOULD ').($cur ? "RESET {$row['id']} (queue {$cur->queue} → {$row['queue']})" : "RE-INSERT {$row['id']}")."\n";
        if ($exec) $cur ? DB::table('jobs')->where('id', $row['id'])->update($row) : DB::table('jobs')->insert($row);
    }
    echo $exec ? "Done.\n" : "Dry-run : nothing changed.\n"; exit(0);
}
echo "Unknown mode\n"; exit(1);
