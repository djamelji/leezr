<?php
// ADR-813 — DEV queue tool. Never runs a job itself.
//   php docs/bmad/adr813/queue-isolation.php inventory <out.json>
//       READ-ONLY : type, company, date, effect, risk of every pending job.
//   php docs/bmad/adr813/queue-isolation.php isolate <companyId> <id,id,...> [--execute]
//       Moves ONLY the listed jobs to the queue `recette-<companyId>`, after checking each one is
//       identifiably owned by that company (refuses otherwise). Dry-run unless --execute.
//       Then : php artisan queue:work database --queue=recette-<companyId> --stop-when-empty --tries=1
//       A worker bound to that queue cannot consume any other company's job.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$int = fn ($cmd, $prop) => preg_match('/'.preg_quote($prop, '/').'";i:(\d+)/', $cmd, $m) ? (int) $m[1] : null;
$companyName = fn ($id) => $id ? (DB::table('companies')->where('id', $id)->value('name') ?? '?') : null;
$mode = $argv[1] ?? 'inventory';
$out = [];
foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
    $p = json_decode($j->payload, true); $cmd = $p['data']['command']; $type = class_basename($p['displayName']);
    $cid = null; $effect = ''; $risk = '';
    switch ($type) {
        case 'SendMembershipInvitationJob':
            $cid = $int($cmd, 'tenantCompanyId'); $mid = $int($cmd, 'membershipId');
            $m = DB::table('memberships')->find($mid);
            $email = $m ? DB::table('users')->where('id', $m->user_id)->value('email') : null;
            $effect = "Send invitation e-mail (membership {$mid}".($email ? ", {$email}" : ', membership gone').')';
            $risk = $m ? 'Real e-mail to a person ; creates a password token / updates invitation delivery state + audit' : 'Fails (membership deleted) then retries';
            break;
        case 'ProcessDocumentAiJob':
            $did = $int($cmd, 'documentId'); $cls = preg_match('/documentClass";s:\d+:"([^"]+)"/', $cmd, $m) ? $m[1] : null;
            $doc = $cls && class_exists($cls) ? $cls::withoutGlobalScopes()->find($did) : null;
            $cid = $doc->company_id ?? null;
            $effect = 'AI analysis of document '.class_basename((string) $cls)." #{$did} (queue ai)";
            $risk = $doc ? 'Calls the AI provider (cost) and writes analysis results on the document' : 'Fails (document gone)';
            break;
        case 'ScanOnboardingUploadJob':
            $cid = $int($cmd, 'tenantCompanyId'); $uid = $int($cmd, 'uploadId');
            $effect = "Antivirus scan of onboarding upload #{$uid}"; $risk = 'Releases or keeps the file in quarantine (fail-closed)';
            break;
        case 'SendQueuedNotifications':
            break;
        default:
            if (str_contains($p['displayName'], 'Notification') || str_contains($p['displayName'], 'Billing')) break;
    }
    if ($type !== 'SendMembershipInvitationJob' && $type !== 'ProcessDocumentAiJob' && $type !== 'ScanOnboardingUploadJob') {
        $uid = preg_match('/App\\\\Core\\\\Models\\\\User";s:2:"id";a:1:\{i:0;i:(\d+);/', $cmd, $m) ? (int) $m[1] : null;
        $email = $uid ? DB::table('users')->where('id', $uid)->value('email') : null;
        if ($type === 'InvoiceCreated') {
            $iid = preg_match('/Invoice";s:2:"id";i:(\d+);/', $cmd, $m) ? (int) $m[1] : null;
            $inv = $iid ? DB::table('invoices')->find($iid) : null;
            $cid = $inv->company_id ?? null;
            $effect = "E-mail « invoice created » ".($inv->number ?? "#{$iid}")." to {$email}";
            $risk = $inv ? 'Real e-mail to the company owner about an invoice ; no billing write' : 'Fails (invoice gone) → failed_jobs';
        } elseif ($type === 'PlanningPublishedNotification') {
            $cids = $uid ? DB::table('memberships')->where('user_id', $uid)->pluck('company_id')->all() : [];
            $cid = count($cids) === 1 ? $cids[0] : null;
            $effect = "E-mail « planning published » to {$email}".(count($cids) > 1 ? ' (member of companies '.implode(',', $cids).' : not identifiable)' : '');
            $risk = 'Real e-mail, stale (planning of a past week)';
        } elseif ($type === 'CompanyInvitationNotification') {
            $name = preg_match('/companyName";s:\d+:"([^"]+)"/', $cmd, $m) ? $m[1] : null;
            $cid = $name ? DB::table('companies')->where('name', $name)->value('id') : null;
            $effect = "E-mail « invitation to join {$name} » to {$email}"; $risk = 'Real e-mail ; link to /invitations';
        }
    }
    $out[] = ['id' => $j->id, 'queue' => $j->queue, 'type' => $type, 'company_id' => $cid, 'company' => $companyName($cid),
        'created' => date('Y-m-d H:i', $j->created_at), 'attempts' => $j->attempts, 'reserved' => (bool) $j->reserved_at, 'effect' => $effect, 'risk' => $risk];
}
if ($mode === 'isolate') {
    $cid = (int) ($argv[2] ?? 0); $ids = array_filter(array_map('intval', explode(',', $argv[3] ?? '')));
    $exec = in_array('--execute', $argv, true);
    $byId = collect($out)->keyBy('id'); $bad = [];
    foreach ($ids as $id) {
        $r = $byId[$id] ?? null;
        if (! $r) { $bad[] = "{$id}: not pending"; continue; }
        if ($r['company_id'] !== $cid) { $bad[] = "{$id}: company ".($r['company_id'] ?? 'not identifiable')." ≠ {$cid}"; continue; }
        if ($r['reserved']) { $bad[] = "{$id}: reserved by a worker"; }
    }
    if (! $cid || ! $ids || $bad) { echo "REFUSED\n".implode("\n", $bad)."\n"; exit(1); }
    foreach ($ids as $id) echo ($exec ? 'MOVE ' : 'WOULD MOVE ')."{$id} {$byId[$id]['type']} → recette-{$cid}\n";
    if ($exec) { DB::table('jobs')->whereIn('id', $ids)->whereNull('reserved_at')->update(['queue' => "recette-{$cid}"]); }
    echo $exec ? "Done. Run : php artisan queue:work database --queue=recette-{$cid} --stop-when-empty --tries=1\n" : "Dry-run : nothing changed.\n";
    exit(0);
}
file_put_contents($argv[2] ?? 'php://stdout', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($out), " jobs\n";
$g = collect($out)->groupBy(fn ($r) => $r['type'].' | company '.($r['company_id'] ?? '?'));
foreach ($g as $k => $rows) echo str_pad((string) count($rows), 3, ' ', STR_PAD_LEFT), ' ', $k, ' | ', $rows->min('created'), ' → ', $rows->max('created'), ' | ', $rows->first()['company'], "\n";
