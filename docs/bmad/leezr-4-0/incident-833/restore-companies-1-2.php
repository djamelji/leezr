<?php

/**
 * ADR-833 — targeted restoration of the DEV rows rewritten by the legacy DevSeeder on 2026-11-19 16:28 (UTC+1).
 *
 *   php restore-companies-1-2.php <mysql-option-file> [--apply --confirm-database=<db>]
 *
 * - Default = DRY RUN: everything runs inside ONE transaction, the final state is verified, then ROLLBACK.
 * - --apply commits only if --confirm-database equals the database the connection is REALLY on, every
 *   precondition matched (rows still exactly as the seeder left them) and every postcondition holds.
 * - Rows are located by business keys (company, role key, permission key, module key, field code, document code),
 *   never by ids copied from another database. Never runs on the production database.
 * - Restores ONLY what the seeder changed and nothing depends on (see LEEZR-4-0-DEPLOYMENT-INCIDENT-FINAL.md §6):
 *   R1 role permissions (companies 1, 2), R2 company 2 subscription, R3 company 2 document requirements,
 *   R4 field activation naf_code (company 1), R5 company_modules workforce_referral rows created by the seeder.
 *   Kept on purpose: preset snapshots + audit logs (history), demo password re-hash (same value), updated_at
 *   touches without value change, and all later legitimate activity (logins, shift, dashboard layout…).
 */

[$self, $cnf] = [$argv[0] ?? '', $argv[1] ?? ''];
$apply = in_array('--apply', $argv, true);
$confirm = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--confirm-database=')) {
        $confirm = substr($a, 19);
    }
}
if ($cnf === '' || ! is_readable($cnf)) {
    fwrite(STDERR, "usage: php {$self} <mysql-option-file> [--apply --confirm-database=<db>]\n");
    exit(2);
}
$ini = parse_ini_file($cnf, true, INI_SCANNER_RAW)['client'] ?? [];
$db = $ini['database'] ?? getenv('RESTORE_DATABASE') ?: '';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $ini['host'] ?? '127.0.0.1', $ini['port'] ?? '3306', $db);
$pdo = new PDO($dsn, $ini['user'] ?? '', trim($ini['password'] ?? '', '"\''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (in_array($actual, ['c1leezr', 'leezr_prod'], true)) {
    fwrite(STDERR, "REFUSED: {$actual} is a production database\n");
    exit(2);
}
if ($apply && $confirm !== $actual) {
    fwrite(STDERR, "REFUSED: --apply requires --confirm-database={$actual}\n");
    exit(2);
}

$failures = [];
$log = function (string $line) { echo $line, "\n"; };
$one = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$exec = function (string $label, string $sql, array $p, int $expected) use ($pdo, $log, &$failures) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $n = $s->rowCount();
    $log(sprintf('  %-4s %s : %d ligne(s) (attendu %d)', $n === $expected ? 'OK' : 'KO', $label, $n, $expected));
    if ($n !== $expected) {
        $failures[] = $label;
    }
};
$role = fn (int $company, string $key) => $one('SELECT id FROM company_roles WHERE company_id = ? AND `key` = ?', [$company, $key]);
$perm = fn (string $key) => $one('SELECT id FROM company_permissions WHERE `key` = ?', [$key]);
$has = fn (int $roleId, int $permId) => (int) $one('SELECT COUNT(*) FROM company_role_permission WHERE company_role_id = ? AND company_permission_id = ?', [$roleId, $permId]);

$log("# ADR-833 restauration ciblée — base {$actual} — ".($apply ? 'APPLICATION' : 'SIMULATION (rollback)'));
$pdo->beginTransaction();
try {
    $configure = (int) $perm('documents.configure');
    $confidential = (int) $perm('documents.view_confidential');

    // R1 — role permissions: seeder removed documents.configure from dispatcher/ops_manager and added
    //      documents.view_confidential to manager/owner (companies 1 and 2). Preconditions = seeder state.
    $log('R1 permissions des rôles');
    foreach ([1, 2] as $c) {
        foreach (['dispatcher', 'ops_manager'] as $k) {
            $r = (int) $role($c, $k);
            if ($r === 0 || $has($r, $configure) !== 0) { $failures[] = "R1 pre {$c}/{$k}"; continue; }
            $exec("R1 société {$c} {$k} + documents.configure", 'INSERT INTO company_role_permission (company_role_id, company_permission_id) VALUES (?, ?)', [$r, $configure], 1);
        }
        foreach (['manager', 'owner'] as $k) {
            $r = (int) $role($c, $k);
            if ($r === 0 || $has($r, $confidential) !== 1) { $failures[] = "R1 pre {$c}/{$k}"; continue; }
            $exec("R1 société {$c} {$k} − documents.view_confidential", 'DELETE FROM company_role_permission WHERE company_role_id = ? AND company_permission_id = ?', [$r, $confidential], 1);
        }
    }

    // R2 — company 2 subscription: back to the state the billing scheduler had legitimately reached (expired).
    $log('R2 abonnement société 2');
    $exec('R2 trialing (seeder) → expired', "UPDATE subscriptions SET status = 'expired', is_current = NULL,
            current_period_start = '2026-11-09 19:13:54', current_period_end = '2026-11-16 19:13:54',
            trial_ends_at = '2026-11-16 19:13:54', updated_at = '2026-11-17 00:00:07'
          WHERE company_id = 2 AND plan_key = 'pro' AND status = 'trialing' AND is_current = 1
            AND trial_ends_at = '2026-11-26 16:28:16'", [], 1);

    // R3 — company 2 document requirements: required_override 0 (seeder) → 1 (company decision of 2026-10-29).
    $log('R3 pièces obligatoires société 2');
    $exec('R3 id_card / insurance_certificate / kbis → obligatoires', "UPDATE document_type_activations a
            JOIN document_types dt ON dt.id = a.document_type_id
          SET a.required_override = 1, a.updated_at = '2026-10-29 20:27:22'
          WHERE a.company_id = 2 AND dt.code IN ('id_card', 'insurance_certificate', 'kbis') AND a.required_override = 0", [], 3);

    // R4 — field naf_code activated on company 1 by the seeder (no value recorded for it).
    $log('R4 champ naf_code société 1');
    $def = (int) $one("SELECT id FROM field_definitions WHERE code = 'naf_code' AND scope = 'company'");
    if ((int) $one('SELECT COUNT(*) FROM field_values WHERE field_definition_id = ?', [$def]) !== 0) { $failures[] = 'R4 pre: values exist'; }
    $exec('R4 activation créée par le seeder', "DELETE FROM field_activations WHERE company_id = 1 AND field_definition_id = ? AND created_at BETWEEN '2026-11-19 16:28:00' AND '2026-11-19 16:29:00'", [$def], 1);

    // R5 — workforce_referral rows the seeder attached (disabled, no setup, no referral data).
    $log('R5 module workforce_referral (lignes créées par le seeder)');
    if ((int) $one("SELECT COUNT(*) FROM company_module_setups WHERE module_key = 'workforce_referral'") + (int) $one('SELECT COUNT(*) FROM workforce_referrals') + (int) $one('SELECT COUNT(*) FROM workforce_referral_settings') !== 0) { $failures[] = 'R5 pre: referral data exists'; }
    $exec('R5 company_modules sociétés 1 et 2', "DELETE FROM company_modules WHERE module_key = 'workforce_referral' AND company_id IN (1, 2) AND is_enabled_for_company = 0 AND created_at BETWEEN '2026-11-19 16:28:00' AND '2026-11-19 16:29:00'", [], 2);

    // Postconditions (state equal to the pre-deploy backup for every restored item)
    $log('Vérifications finales');
    $post = [
        'configure dispatcher/ops_manager (1,2)' => [(int) $one("SELECT COUNT(*) FROM company_role_permission rp JOIN company_roles r ON r.id = rp.company_role_id WHERE r.company_id IN (1,2) AND r.`key` IN ('dispatcher','ops_manager') AND rp.company_permission_id = ?", [$configure]), 4],
        'view_confidential (1,2)' => [(int) $one('SELECT COUNT(*) FROM company_role_permission rp JOIN company_roles r ON r.id = rp.company_role_id WHERE r.company_id IN (1,2) AND rp.company_permission_id = ?', [$confidential]), 0],
        'subscription 2 expired' => [(int) $one("SELECT COUNT(*) FROM subscriptions WHERE company_id = 2 AND status = 'expired' AND is_current IS NULL"), 1],
        'company 2 required docs' => [(int) $one("SELECT COUNT(*) FROM document_type_activations a JOIN document_types dt ON dt.id = a.document_type_id WHERE a.company_id = 2 AND dt.code IN ('id_card','insurance_certificate','kbis') AND a.required_override = 1"), 3],
        'naf_code company 1' => [(int) $one('SELECT COUNT(*) FROM field_activations WHERE company_id = 1 AND field_definition_id = ?', [$def]), 0],
        'workforce_referral company rows' => [(int) $one("SELECT COUNT(*) FROM company_modules WHERE module_key = 'workforce_referral' AND company_id IN (1,2)"), 0],
    ];
    foreach ($post as $label => [$got, $want]) {
        $log(sprintf('  %-4s %s = %d (attendu %d)', $got === $want ? 'OK' : 'KO', $label, $got, $want));
        if ($got !== $want) { $failures[] = "post {$label}"; }
    }

    if ($failures !== [] || ! $apply) {
        $pdo->rollBack();
        $log($failures === [] ? 'SIMULATION VERTE — rollback effectué, aucune écriture conservée.' : 'ÉCHEC — rollback : '.implode(', ', $failures));
        exit($failures === [] ? 0 : 1);
    }
    $pdo->commit();
    $log('APPLIQUÉ — transaction validée.');
} catch (Throwable $e) {
    $pdo->inTransaction() && $pdo->rollBack();
    fwrite(STDERR, 'ERREUR — rollback : '.$e->getMessage()."\n");
    exit(1);
}
