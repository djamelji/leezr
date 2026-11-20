#!/usr/bin/env bash
# ADR-833 / OP-2bis — self-contained bundle, run BY THE OWNER on PROD (web2, base c1leezr) from their own terminal:
#
#   simulation : ssh jliouiprodleezr@213.32.20.37 'bash -s -- --only=platform_users:2,3 --only=users:1,2,3,4,5 --allow-production' < prod-lock-bundle.sh
#   application: ssh jliouiprodleezr@213.32.20.37 'bash -s -- --only=platform_users:2,3 --only=users:1,2,3,4,5 --allow-production --apply --confirm-database=c1leezr' < prod-lock-bundle.sh
#   preuve     : relancer la simulation → doit répondre « REFUSED: users #1 no longer uses the public password ».
#
# DB credentials are read from web2/shared/.env into a 600 temp file deleted on exit; nothing secret is printed.
# Embedded below: lock-demo-accounts.php, verbatim (same file, tested on clone leezr_clone_op2bis).
set -euo pipefail
APP="${LEEZR_APP:-/var/www/clients/client1/web2}"; ENVF="$APP/shared/.env"
ev() { grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"; }
DB="$(ev DB_DATABASE)"; [ "$DB" = "${EXPECT_DB:-c1leezr}" ] || { echo "REFUS : base inattendue ($DB)" >&2; exit 2; }
W="$(mktemp -d)"; chmod 700 "$W"; trap 'rm -rf "$W"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\ndatabase=%s\n' "$(ev DB_HOST)" "$(ev DB_PORT)" "$(ev DB_USERNAME)" "$(ev DB_PASSWORD)" "$DB" > "$W/c.cnf"; chmod 600 "$W/c.cnf"
cat > "$W/lock.php" <<'LEEZR_LOCK_PHP'
<?php

/**
 * ADR-833 — lock the accounts that still accept the PUBLIC demo password ("password", visible in the public
 * repository's DevSeeder).
 *
 *   php lock-demo-accounts.php <mysql-option-file> [--only=<table>:<id,id>]... [--export]
 *                              [--apply --confirm-database=<db>] [--allow-production]
 *
 * Without --only: every users / platform_users row whose hash verifies the public password (DEV usage).
 * With --only: EXACTLY the listed ids; each one must exist and still verify the public password, otherwise the run
 * aborts before any write (an account with a private password is never touched).
 *
 * For each locked account:
 *   - password ← bcrypt of 48 random bytes, generated in memory and NEVER printed or stored anywhere;
 *   - remember_token ← NULL; its sessions (found by session.user_id / session.platform_user_id when the column exists
 *     AND by the guard login key inside the session payload), Sanctum tokens and pending reset tokens are removed.
 * Nothing else is written: no role, permission, membership, company or business row.
 *
 * --export        : prints a JSON backup of every row the run would change or delete (no write) and exits.
 * Default         : DRY RUN — one transaction, every postcondition verified, ROLLBACK.
 * --apply         : same transaction, COMMIT only if every postcondition holds; requires --confirm-database=<db>.
 * Production databases (c1leezr, leezr_prod) are refused unless --allow-production AND --only are both given.
 */

[$self, $cnf] = [$argv[0] ?? '', $argv[1] ?? ''];
$apply = in_array('--apply', $argv, true);
$export = in_array('--export', $argv, true);
$allowProd = in_array('--allow-production', $argv, true);
$confirm = null;
$only = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--confirm-database=')) {
        $confirm = substr($a, 19);
    }
    if (str_starts_with($a, '--only=')) {
        [$t, $ids] = array_pad(explode(':', substr($a, 7), 2), 2, '');
        if (! in_array($t, ['users', 'platform_users'], true) || ! preg_match('/^\d+(,\d+)*$/', $ids)) {
            fwrite(STDERR, "REFUSED: bad --only value '{$a}'\n");
            exit(2);
        }
        $only[$t] = array_map('intval', explode(',', $ids));
    }
}
if ($cnf === '' || ! is_readable($cnf)) {
    fwrite(STDERR, "usage: php {$self} <mysql-option-file> [--only=<table>:<ids>]... [--export] [--apply --confirm-database=<db>] [--allow-production]\n");
    exit(2);
}
$ini = parse_ini_file($cnf, true, INI_SCANNER_RAW)['client'] ?? [];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $ini['host'] ?? '127.0.0.1', $ini['port'] ?? '3306', $ini['database'] ?? '');
$pdo = new PDO($dsn, $ini['user'] ?? '', trim($ini['password'] ?? '', '"\''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (in_array($actual, ['c1leezr', 'leezr_prod'], true) && ! ($allowProd && $only !== [])) {
    fwrite(STDERR, "REFUSED: {$actual} is a production database (requires --allow-production and an explicit --only list)\n");
    exit(2);
}
if ($apply && $confirm !== $actual) {
    fwrite(STDERR, "REFUSED: --apply requires --confirm-database={$actual}\n");
    exit(2);
}

$mask = fn (string $e) => preg_replace('/^(.).*(@.*)$/', '$1…$2', $e);
$fresh = fn () => password_hash(bin2hex(random_bytes(48)), PASSWORD_BCRYPT, ['cost' => 12]);
$isDemo = fn ($hash) => is_string($hash) && password_verify('password', $hash);
$tables = [
    'users' => ['guard' => 'web', 'col' => 'user_id', 'tokens' => 'user', 'resets' => 'password_reset_tokens'],
    'platform_users' => ['guard' => 'platform', 'col' => 'platform_user_id', 'tokens' => 'platform_user', 'resets' => 'platform_password_reset_tokens'],
];
$sessionCols = $pdo->query('SHOW COLUMNS FROM sessions')->fetchAll(PDO::FETCH_COLUMN);

// Sessions of an account: by the id column when present, and by the guard login key in the payload (any schema).
$sessionsOf = function (string $table, int $id) use ($pdo, $tables, $sessionCols): array {
    $rel = $tables[$table];
    $ids = [];
    foreach ($pdo->query('SELECT id, '.(in_array($rel['col'], $sessionCols, true) ? $rel['col'] : 'NULL').' AS owner, payload FROM sessions') as $s) {
        $payload = base64_decode((string) $s['payload'], true) ?: (string) $s['payload'];
        $byKey = preg_match('/login_'.$rel['guard'].'_[0-9a-f]{40}";i:'.$id.';/', $payload) === 1;
        if ($byKey || ((int) $s['owner'] === $id && $s['owner'] !== null)) {
            $ids[] = $s['id'];
        }
    }

    return $ids;
};

// Targets
$targets = [];
foreach ($tables as $table => $rel) {
    $rows = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
    $byId = array_column($rows, null, 'id');
    if ($only === []) {
        foreach ($rows as $r) {
            $isDemo($r['password']) && $targets[$table][] = (int) $r['id'];
        }

        continue;
    }
    foreach ($only[$table] ?? [] as $id) {
        if (! isset($byId[$id])) {
            fwrite(STDERR, "REFUSED: {$table} #{$id} does not exist\n");
            exit(2);
        }
        if (! $isDemo($byId[$id]['password'])) {
            fwrite(STDERR, "REFUSED: {$table} #{$id} no longer uses the public password — not touched\n");
            exit(2);
        }
        $targets[$table][] = $id;
    }
}

if ($export) {
    $out = ['database' => $actual, 'exported_at' => gmdate('c'), 'tables' => []];
    foreach ($targets as $table => $ids) {
        $rel = $tables[$table];
        foreach ($ids as $id) {
            $row = $pdo->query("SELECT * FROM {$table} WHERE id = ".(int) $id)->fetch();
            $sids = $sessionsOf($table, $id);
            $out['tables'][$table][] = [
                'row' => $row,
                'sessions' => $sids ? $pdo->query('SELECT * FROM sessions WHERE id IN ('.implode(',', array_map([$pdo, 'quote'], $sids)).')')->fetchAll() : [],
                'personal_access_tokens' => ($t = $pdo->prepare('SELECT * FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?')) && $t->execute([$rel['tokens'], $id]) ? $t->fetchAll() : [],
                $rel['resets'] => ($r = $pdo->prepare("SELECT * FROM {$rel['resets']} WHERE email = ?")) && $r->execute([$row['email']]) ? $r->fetchAll() : [],
            ];
        }
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

// Fingerprint of everything that must NOT change: every non-target account row, every column of the targets except
// password/remember_token, and the role / permission / membership tables.
$guarded = ['platform_role_user', 'platform_roles', 'memberships', 'company_roles', 'company_role_permissions', 'companies'];
$fingerprint = function () use ($pdo, $tables, &$targets, $guarded): array {
    $fp = [];
    foreach (array_keys($tables) as $table) {
        foreach ($pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll() as $r) {
            if (in_array((int) $r['id'], $targets[$table] ?? [], true)) {
                unset($r['password'], $r['remember_token']);
            }
            $fp["{$table}#{$r['id']}"] = sha1(json_encode($r));
        }
    }
    foreach ($guarded as $t) {
        try {
            $fp[$t] = sha1(json_encode($pdo->query("SELECT * FROM {$t}")->fetchAll()));
        } catch (Throwable) {
            $fp[$t] = 'absent';
        }
    }

    return $fp;
};

echo "# ADR-833 verrouillage des comptes à mot de passe public — base {$actual} — ".($apply ? 'APPLICATION' : 'SIMULATION (rollback)').($only ? ' — liste explicite' : '')."\n";
$pdo->beginTransaction();
try {
    $before = $fingerprint();
    $locked = 0;
    foreach ($targets as $table => $ids) {
        $rel = $tables[$table];
        foreach ($ids as $id) {
            $email = $pdo->query("SELECT email FROM {$table} WHERE id = ".(int) $id)->fetchColumn();
            $u = $pdo->prepare("UPDATE {$table} SET password = ?, remember_token = NULL WHERE id = ?");
            $u->execute([$fresh(), $id]);
            $sids = $sessionsOf($table, $id);
            $sc = $sids ? $pdo->exec('DELETE FROM sessions WHERE id IN ('.implode(',', array_map([$pdo, 'quote'], $sids)).')') : 0;
            $t = $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?');
            $t->execute([$rel['tokens'], $id]);
            $r = $pdo->prepare("DELETE FROM {$rel['resets']} WHERE email = ?");
            $r->execute([$email]);
            $locked++;
            printf("  %-15s %3d %-24s verrouillé (lignes %d, sessions %d, jetons %d, réinitialisations %d)\n", $table, $id, $mask($email), $u->rowCount(), $sc, $t->rowCount(), $r->rowCount());
        }
    }
    // Postconditions
    $ko = [];
    foreach ($targets as $table => $ids) {
        foreach ($ids as $id) {
            $h = $pdo->query("SELECT password FROM {$table} WHERE id = ".(int) $id)->fetchColumn();
            $isDemo($h) && $ko[] = "{$table}#{$id} accepte encore le mot de passe public";
            foreach ($sessionsOf($table, $id) as $_) {
                $ko[] = "{$table}#{$id} a encore une session";
            }
        }
    }
    $after = $fingerprint();
    foreach ($before as $k => $v) {
        ($after[$k] ?? null) !== $v && $ko[] = "{$k} modifié hors périmètre";
    }
    if ($only === []) {
        foreach (array_keys($tables) as $table) {
            foreach ($pdo->query("SELECT id, password FROM {$table}") as $row) {
                $isDemo($row['password']) && $ko[] = "{$table}#{$row['id']} accepte encore le mot de passe public";
            }
        }
    }
    printf("  %s comptes verrouillés : %d ; empreintes hors périmètre contrôlées : %d ; écarts : %d\n", $ko ? 'KO ' : 'OK ', $locked, count($before), count($ko));
    foreach ($ko as $line) {
        echo "    - {$line}\n";
    }
    if ($ko || ! $apply) {
        $pdo->rollBack();
        echo $ko ? "ÉCHEC — rollback.\n" : "SIMULATION VERTE — rollback effectué.\n";
        exit($ko ? 1 : 0);
    }
    $pdo->commit();
    echo "APPLIQUÉ — transaction validée.\n";
} catch (Throwable $e) {
    $pdo->inTransaction() && $pdo->rollBack();
    fwrite(STDERR, 'ERREUR — rollback : '.$e->getMessage()."\n");
    exit(1);
}

LEEZR_LOCK_PHP
"${PHP_BIN:-/usr/bin/php8.4}" "$W/lock.php" "$W/c.cnf" "$@"
