<?php
/**
 * ADR-827 — controlled restoration of the DEV company 1 April 2026 payroll deleted on 2026-11-18 08:04:01 UTC
 * (incident ADR-826 : e2e:reset-payroll + e2e:prepare-payroll run against DEV).
 *
 * Source : rows-binlog-verified.json — the DELETE before-images of the MySQL binary log, cross-checked byte-identical
 * (SHA-256 per row) with the 3.6 script. Nothing is recomputed : the rows are put back exactly as they were.
 *
 * Standalone (no Laravel, no .env) : the target is given explicitly and verified against the live server.
 *
 *   php restore-company1-april2026.php --defaults-file=<mysql option file> --database=<db>            # simulation
 *   php restore-company1-april2026.php ... --apply --confirm-database=<db> --backup-dir=<dir>         # restoration
 *   php restore-company1-april2026.php ... --rollback=<backup.json> [--apply --confirm-database=<db>]  # back-out
 *
 * Refuses (exit 2, nothing written) : database mismatch, schema drift, missing company / employee, any occupied id
 * or unique key held by another row, a period already present for company 1, sheet 143 modified or with
 * dependents, partial state. Idempotent : a second run on a restored database reports already_restored, exit 0.
 */
const COMPANY = 1;
const EMPLOYEE = 3;
const SHEET_INCIDENT = 143;

$opt = getopt('', ['defaults-file:', 'database:', 'apply', 'confirm-database:', 'backup-dir:', 'rollback:', 'data:']);
$data = json_decode(file_get_contents($opt['data'] ?? __DIR__.'/rows-binlog-verified.json'), true, flags: JSON_THROW_ON_ERROR);
$apply = isset($opt['apply']);
$db = $opt['database'] ?? null;
$report = ['tool' => 'restore-company1-april2026', 'mode' => isset($opt['rollback']) ? 'rollback' : 'restore', 'apply' => $apply, 'checks' => [], 'conflicts' => []];

function out(array $r, int $code): never
{
    fwrite(STDOUT, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    exit($code);
}
if (! $db || ! isset($opt['defaults-file'])) {
    $report['conflicts'][] = 'usage : --defaults-file and --database are required';
    out($report, 2);
}
if ($apply && ($opt['confirm-database'] ?? null) !== $db) {
    $report['conflicts'][] = '--apply requires --confirm-database equal to --database';
    out($report, 2);
}
$ini = parse_ini_file($opt['defaults-file'], true, INI_SCANNER_RAW)['client'] ?? [];
$dsn = isset($ini['socket']) ? "mysql:unix_socket={$ini['socket']}" : 'mysql:host='.($ini['host'] ?? 'localhost').';port='.($ini['port'] ?? 3306);
$pdo = new PDO("$dsn;dbname=$db;charset=utf8mb4", $ini['user'] ?? 'root', $ini['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET SESSION time_zone='+00:00'");

// C0 — the live connection really is the requested database
$live = $pdo->query('SELECT DATABASE() d, @@server_uuid u, @@hostname h')->fetch(PDO::FETCH_ASSOC);
$report['target'] = $live;
if ($live['d'] !== $db) {
    $report['conflicts'][] = "connected database {$live['d']} differs from --database $db";
    out($report, 2);
}

$cols = fn (string $t) => $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
$hashExpr = fn (string $t) => 'SHA2(CONCAT_WS(\'|\','.implode(',', array_map(fn ($c) => "IFNULL(`$c`,'<NULL>')", $cols($t))).'),256)';
$rowHash = function (string $t, int $id) use ($pdo, $hashExpr) {
    $s = $pdo->prepare("SELECT {$hashExpr($t)} FROM `$t` WHERE id=? AND company_id=?");
    $s->execute([$id, COMPANY]);

    return $s->fetchColumn() ?: null;
};
$one = function (string $sql, array $b = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($b);

    return $s->fetchColumn();
};

// C1 — no schema drift : the target columns are exactly the columns of the verified rows, in order
foreach (array_merge($data['restore'], [$data['incident_insert']]) as $r) {
    if ($cols($r['table']) !== array_keys($r['row'])) {
        $report['conflicts'][] = "schema drift on {$r['table']} : columns differ from the verified rows";
    }
}
if ($report['conflicts']) {
    out($report, 2);
}
$report['checks'][] = 'schema identical to the verified rows';

// C2 — owner rows exist
if (! $one('SELECT COUNT(*) FROM companies WHERE id=?', [COMPANY]) || ! $one('SELECT COUNT(*) FROM workforce_employees WHERE id=? AND company_id=?', [EMPLOYEE, COMPANY])) {
    $report['conflicts'][] = 'company 1 or employee 3 (company 1) missing';
    out($report, 2);
}

/** Current state of every concerned row and key. */
$assess = function () use ($data, $rowHash, $one) {
    $present = [];
    $conflicts = [];
    foreach ($data['restore'] as $r) {
        $id = (int) $r['row']['id'];
        $anyCompany = $one("SELECT company_id FROM `{$r['table']}` WHERE id=?", [$id]);
        if ($anyCompany === false) {
            $present[$r['table']] = false;

            continue;
        }
        $h = $rowHash($r['table'], $id);
        if ($h === $r['sha256']) {
            $present[$r['table']] = true;
        } else {
            $conflicts[] = "{$r['table']} id $id is occupied by a different row (company $anyCompany)";
        }
    }
    // unique keys held by OTHER rows
    $u = [
        ['SELECT COUNT(*) FROM workforce_payroll_runs WHERE company_id=? AND id<>11 AND period_start<=? AND period_end>=?', [COMPANY, '2026-04-30', '2026-04-01'], 'a payroll run of company 1 already covers April 2026'],
        ['SELECT COUNT(*) FROM workforce_payroll_runs WHERE company_id=? AND idempotency_key=? AND id<>11', [COMPANY, 'payroll:1:2026-04-01:2026-04-30'], 'the idempotency key of run 11 is held by another run'],
        ['SELECT COUNT(*) FROM workforce_payroll_lines WHERE payroll_run_id=11 AND employee_id=? AND id<>9', [EMPLOYEE], 'run 11 already has another line for employee 3'],
        ['SELECT COUNT(*) FROM workforce_payroll_calculations WHERE payroll_line_id=9 AND id<>9', [], 'line 9 already has another calculation'],
        ['SELECT COUNT(*) FROM workforce_payroll_ytd_snapshots WHERE ((company_id=? AND employee_id=? AND fiscal_year=2026 AND period_month=4) OR payroll_calculation_id=9) AND id<>9', [COMPANY, EMPLOYEE], 'a year-to-date snapshot already exists for employee 3, April 2026'],
        ['SELECT COUNT(*) FROM workforce_timesheet_periods WHERE company_id=? AND employee_id=? AND period_start=? AND period_end=? AND id NOT IN (17,?)', [COMPANY, EMPLOYEE, '2026-04-01', '2026-04-30', SHEET_INCIDENT], 'another April 2026 timesheet exists for employee 3'],
    ];
    foreach ($u as [$sql, $b, $msg]) {
        if ((int) $one($sql, $b) > 0) {
            $conflicts[] = $msg;
        }
    }

    return [$present, $conflicts];
};
$sheet143 = function () use ($rowHash, $one, $data) {
    if ($one('SELECT COUNT(*) FROM workforce_timesheet_periods WHERE id=?', [SHEET_INCIDENT]) == 0) {
        return ['state' => 'absent'];
    }
    $deps = (int) $one('SELECT COUNT(*) FROM workforce_timesheet_lines WHERE timesheet_period_id=?', [SHEET_INCIDENT])
        + (int) $one('SELECT COUNT(*) FROM workforce_payroll_lines WHERE timesheet_period_id=?', [SHEET_INCIDENT]);
    $h = $rowHash('workforce_timesheet_periods', SHEET_INCIDENT);

    return ['state' => $h === $data['incident_insert']['sha256'] ? 'as_created_by_incident' : 'modified', 'dependents' => $deps];
};

$insert = function (string $t, array $row) use ($pdo) {
    $c = array_keys($row);
    $s = $pdo->prepare("INSERT INTO `$t` (`".implode('`,`', $c).'`) VALUES ('.implode(',', array_fill(0, count($c), '?')).')');
    $s->execute(array_values($row));
};

if (isset($opt['rollback'])) {
    goto rollback;
}

[$present, $conflicts] = $assess();
$s143 = $sheet143();
$report['state'] = ['restored_rows_present' => $present, 'sheet_143' => $s143];
$report['conflicts'] = $conflicts;
if ($s143['state'] === 'modified') {
    $report['conflicts'][] = 'sheet 143 was modified after the incident : refused, decide manually';
}
if (($s143['dependents'] ?? 0) > 0) {
    $report['conflicts'][] = 'sheet 143 has dependent rows (timesheet lines or payroll lines) : refused';
}
$nPresent = count(array_filter($present));
if ($nPresent === count($data['restore']) && $s143['state'] === 'absent' && ! $report['conflicts']) {
    $report['result'] = 'already_restored';
    out($report, 0);
}
if ($nPresent > 0 && ! $report['conflicts']) {
    $report['conflicts'][] = "partial state : $nPresent of ".count($data['restore']).' rows already present : refused';
}
if ($report['conflicts']) {
    $report['result'] = 'refused';
    out($report, 2);
}
if (! $apply) {
    $report['result'] = 'would_restore';
    $report['plan'] = ['backup then delete sheet 143 (the incident copy, unchanged)', 'insert timesheet 17, run 11, line 9, calculation 9, ytd snapshot 9 (verified rows)', 'verify every row hash, commit'];
    out($report, 0);
}

// APPLY — backup first, outside the transaction
$dir = $opt['backup-dir'] ?? null;
if (! $dir || ! is_dir($dir) || ! is_writable($dir)) {
    $report['conflicts'][] = '--apply requires a writable --backup-dir';
    out($report, 2);
}
$backup = ['database' => $db, 'server_uuid' => $live['u'], 'taken_at' => gmdate('c'), 'sheet_143' => null, 'restored' => array_map(fn ($r) => ['table' => $r['table'], 'id' => $r['row']['id'], 'sha256' => $r['sha256']], $data['restore'])];
if ($s143['state'] !== 'absent') {
    $st = $pdo->prepare('SELECT * FROM workforce_timesheet_periods WHERE id=? AND company_id=?');
    $st->execute([SHEET_INCIDENT, COMPANY]);
    $backup['sheet_143'] = $st->fetch(PDO::FETCH_ASSOC);
}
$backupFile = rtrim($dir, '/')."/backup-{$db}-".gmdate('Ymd\THis\Z').'.json';
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
$report['backup'] = $backupFile;

try {
    $pdo->beginTransaction();
    // re-assess under lock (concurrent writes since the simulation)
    $pdo->query('SELECT id FROM workforce_payroll_runs WHERE company_id='.COMPANY.' FOR UPDATE')->fetchAll();
    $pdo->query('SELECT id FROM workforce_timesheet_periods WHERE company_id='.COMPANY.' AND employee_id='.EMPLOYEE.' FOR UPDATE')->fetchAll();
    [$p2, $c2] = $assess();
    if ($c2 || array_filter($p2) || $sheet143() != $s143) {
        throw new RuntimeException('state changed since the simulation : '.implode(' ; ', $c2));
    }
    if ($s143['state'] !== 'absent') {
        $d = $pdo->prepare("DELETE FROM workforce_timesheet_periods WHERE id=? AND company_id=? AND {$hashExpr('workforce_timesheet_periods')}=?");
        $d->execute([SHEET_INCIDENT, COMPANY, $data['incident_insert']['sha256']]);
        if ($d->rowCount() !== 1) {
            throw new RuntimeException('sheet 143 delete did not match exactly one unchanged row');
        }
    }
    foreach ($data['restore'] as $r) {
        $insert($r['table'], $r['row']);
        if ($rowHash($r['table'], (int) $r['row']['id']) !== $r['sha256']) {
            throw new RuntimeException("hash mismatch after insert on {$r['table']}");
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->inTransaction() && $pdo->rollBack();
    $report['result'] = 'rolled_back';
    $report['error'] = $e->getMessage();
    out($report, 3);
}
$report['result'] = 'restored';
out($report, 0);

rollback:
// BACK-OUT — removes exactly the restored rows (only if unchanged and without new dependents), puts sheet 143 back
$b = json_decode(file_get_contents($opt['rollback']), true, flags: JSON_THROW_ON_ERROR);
if ($b['database'] !== $db) {
    $report['conflicts'][] = 'backup taken on another database';
    out($report, 2);
}
foreach ($b['restored'] as $r) {
    if ($rowHash($r['table'], (int) $r['id']) !== $r['sha256']) {
        $report['conflicts'][] = "{$r['table']} id {$r['id']} absent or changed since the restoration";
    }
}
foreach ([['SELECT COUNT(*) FROM workforce_dsn_declarations WHERE payroll_run_id=11', 'run 11 has DSN declarations'], ['SELECT COUNT(*) FROM workforce_timesheet_lines WHERE timesheet_period_id=17', 'sheet 17 has lines'], ['SELECT COUNT(*) FROM workforce_payroll_lines WHERE payroll_run_id=11 AND id<>9', 'run 11 has new lines']] as [$sql, $msg]) {
    if ((int) $one($sql) > 0) {
        $report['conflicts'][] = $msg;
    }
}
if ($b['sheet_143'] && $one('SELECT COUNT(*) FROM workforce_timesheet_periods WHERE id=?', [SHEET_INCIDENT]) > 0) {
    $report['conflicts'][] = 'sheet 143 id is occupied';
}
if ($report['conflicts']) {
    $report['result'] = 'refused';
    out($report, 2);
}
if (! $apply) {
    $report['result'] = 'would_rollback';
    out($report, 0);
}
try {
    $pdo->beginTransaction();
    foreach (array_reverse($b['restored']) as $r) {
        $d = $pdo->prepare("DELETE FROM `{$r['table']}` WHERE id=? AND company_id=? AND {$hashExpr($r['table'])}=?");
        $d->execute([(int) $r['id'], COMPANY, $r['sha256']]);
        if ($d->rowCount() !== 1) {
            throw new RuntimeException("rollback delete mismatch on {$r['table']}");
        }
    }
    if ($b['sheet_143']) {
        $insert('workforce_timesheet_periods', $b['sheet_143']);
        if ($rowHash('workforce_timesheet_periods', SHEET_INCIDENT) !== $data['incident_insert']['sha256']) {
            throw new RuntimeException('sheet 143 hash mismatch after rollback');
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->inTransaction() && $pdo->rollBack();
    $report['result'] = 'rolled_back';
    $report['error'] = $e->getMessage();
    out($report, 3);
}
$report['result'] = 'rollback_done';
out($report, 0);
