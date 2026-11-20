<?php

/**
 * ADR-833 — lock the DEV accounts that still accept the PUBLIC demo password ("password", visible in the public
 * repository's DevSeeder) on an Internet-facing DEV.
 *
 *   php lock-demo-accounts.php <mysql-option-file> [--apply --confirm-database=<db>]
 *
 * For every users / platform_users row whose hash verifies the public demo password:
 *   - password ← bcrypt of 48 random bytes, generated in memory and NEVER printed or stored anywhere;
 *   - remember_token ← NULL; its sessions, Sanctum tokens and pending reset tokens are removed.
 * Access is recovered afterwards through the existing "forgot password" flow (or an administrator setting a new
 * password through a secure channel). Accounts that already have a private password are not touched.
 * Default = DRY RUN (one transaction, verified, ROLLBACK). Never runs on a production database.
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
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $ini['host'] ?? '127.0.0.1', $ini['port'] ?? '3306', $ini['database'] ?? '');
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

$mask = fn (string $e) => preg_replace('/^(.).*(@.*)$/', '$1…$2', $e);
$fresh = fn () => password_hash(bin2hex(random_bytes(48)), PASSWORD_BCRYPT, ['cost' => 12]);
$tables = [
    'users' => ['sessions' => 'user_id', 'tokens' => 'user', 'resets' => 'password_reset_tokens'],
    'platform_users' => ['sessions' => 'platform_user_id', 'tokens' => 'platform_user', 'resets' => 'platform_password_reset_tokens'],
];

echo "# ADR-833 verrouillage des comptes à mot de passe public — base {$actual} — ".($apply ? 'APPLICATION' : 'SIMULATION (rollback)')."\n";
$pdo->beginTransaction();
$locked = [];
try {
    foreach ($tables as $table => $rel) {
        foreach ($pdo->query("SELECT id, email, password FROM {$table}")->fetchAll() as $row) {
            if (! is_string($row['password']) || ! password_verify('password', $row['password'])) {
                continue;
            }
            $pdo->prepare("UPDATE {$table} SET password = ?, remember_token = NULL WHERE id = ?")->execute([$fresh(), $row['id']]);
            $s = $pdo->prepare("DELETE FROM sessions WHERE {$rel['sessions']} = ?");
            $s->execute([$row['id']]);
            $t = $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?');
            $t->execute([$rel['tokens'], $row['id']]);
            $r = $pdo->prepare("DELETE FROM {$rel['resets']} WHERE email = ?");
            $r->execute([$row['email']]);
            $locked[] = [$table, (int) $row['id']];
            printf("  %-15s %3d %-24s verrouillé (sessions %d, jetons %d, réinitialisations %d)\n", $table, $row['id'], $mask($row['email']), $s->rowCount(), $t->rowCount(), $r->rowCount());
        }
    }
    // Postcondition: no account of either table accepts the public password any more
    $left = 0;
    foreach (array_keys($tables) as $table) {
        foreach ($pdo->query("SELECT password FROM {$table}")->fetchAll() as $row) {
            $left += (int) (is_string($row['password']) && password_verify('password', $row['password']));
        }
    }
    printf("  %s comptes encore au mot de passe public : %d ; comptes verrouillés : %d\n", $left === 0 ? 'OK ' : 'KO ', $left, count($locked));
    if ($left !== 0 || ! $apply) {
        $pdo->rollBack();
        echo $left === 0 ? "SIMULATION VERTE — rollback effectué.\n" : "ÉCHEC — rollback.\n";
        exit($left === 0 ? 0 : 1);
    }
    $pdo->commit();
    echo "APPLIQUÉ — transaction validée. Récupération : parcours « mot de passe oublié ».\n";
} catch (Throwable $e) {
    $pdo->inTransaction() && $pdo->rollBack();
    fwrite(STDERR, 'ERREUR — rollback : '.$e->getMessage()."\n");
    exit(1);
}
