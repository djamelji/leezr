<?php

namespace App\Console\Recipe;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-827 — the database identity check of the recipe (E2E fixture) commands.
 *
 * A recipe command may delete or rewrite data : it only runs against a database that PROVES it is an isolated
 * clone. The proof is read from the live connection, never from the environment alone :
 *
 *  1. APP_ENV production → refused, whatever the database ;
 *  2. the connection must be identifiable : a configured database name (empty / :memory: → refused) ;
 *  3. the database actually connected (SELECT DATABASE(), or the sqlite file) must be the configured one
 *     (a DB_URL or socket pointing elsewhere → refused) ;
 *  4. its name must follow the clone convention (leezr_audit…, leezr_clone…, leezr_recipe…) and must not be a
 *     known DEV / production name — both lists are constants : no variable can widen them ;
 *  5. it must carry the clone marker : table `_recipe_clone_identity` with exactly one row whose `database_name`
 *     is the live name (a clone dump restored into another database loses its proof).
 *
 * Nothing is written by the check. Every refusal is logged to storage/logs/recipe-guard.log.
 * APP_ENV=testing is never a proof on its own : PHPUnit replaces this service explicitly when it tests the
 * logic of a recipe command on its in-memory database.
 */
class RecipeDatabaseGuard
{
    public const MARKER_TABLE = '_recipe_clone_identity';

    public const CLONE_PATTERN = '/^leezr_(audit|clone|recipe)[a-z0-9_]*$/';

    /** DEV and production databases known to the project (local DEV, VPS production, VPS DEV). */
    public const PROTECTED_DATABASES = ['leezr', 'c1leezr', 'c1leezrdev'];

    /** @return array{allowed: bool, reasons: list<string>, identity: array<string, mixed>} */
    public function assess(?string $command = null): array
    {
        $identity = $this->identify();
        $reasons = self::decide($identity, (string) app()->environment());
        $verdict = ['allowed' => $reasons === [], 'reasons' => $reasons, 'identity' => $identity];
        if ($reasons !== []) {
            $this->logRefusal($command, $verdict);
        }

        return $verdict;
    }

    /**
     * Pure decision (unit-tested) from the identity read on the live connection.
     *
     * @param  array<string, mixed>  $identity
     * @return list<string> reasons of refusal, empty when allowed
     */
    public static function decide(array $identity, string $environment): array
    {
        $reasons = [];
        if ($environment === 'production') {
            $reasons[] = 'APP_ENV is production';
        }
        if (! empty($identity['error'])) {
            return [...$reasons, 'connection not identifiable : '.$identity['error']];
        }
        $configured = $identity['configured'] ?? null;
        $actual = $identity['actual'] ?? null;
        if ($configured === null || $configured === '' || $configured === ':memory:') {
            return [...$reasons, 'no identifiable database configured'];
        }
        if ($actual === null || $actual === '') {
            return [...$reasons, 'the live connection reports no database'];
        }
        if ($actual !== $configured) {
            $reasons[] = "the live database ({$actual}) is not the configured one ({$configured})";
        }
        if (in_array($actual, self::PROTECTED_DATABASES, true)) {
            $reasons[] = "{$actual} is a DEV / production database";
        } elseif (! preg_match(self::CLONE_PATTERN, $actual)) {
            $reasons[] = "{$actual} does not follow the clone naming convention";
        }
        $markers = $identity['markers'] ?? null;
        if ($markers === null) {
            $reasons[] = 'no clone marker table ('.self::MARKER_TABLE.')';
        } elseif ($markers !== [$actual]) {
            $reasons[] = 'the clone marker does not name this database';
        }

        return $reasons;
    }

    /** @return array<string, mixed> read-only identity of the default connection */
    public function identify(): array
    {
        $name = config('database.default');
        $config = (array) config("database.connections.{$name}", []);
        $driver = $config['driver'] ?? null;
        $identity = ['connection' => $name, 'driver' => $driver, 'configured' => null, 'actual' => null, 'markers' => null];
        try {
            if ($driver === 'sqlite') {
                $file = (string) ($config['database'] ?? '');
                if ($file === '' || $file === ':memory:' || str_contains($file, 'mode=memory')) {
                    return array_merge($identity, ['configured' => $file]);
                }
                $real = realpath($file) ?: null;
                $identity['configured'] = pathinfo($file, PATHINFO_FILENAME);
                $live = collect(DB::connection($name)->select('PRAGMA database_list'))->firstWhere('name', 'main');
                $identity['actual'] = $live && $real && realpath($live->file) === $real ? pathinfo($live->file, PATHINFO_FILENAME) : ($live ? 'sqlite:'.$live->file : null);
                $hasMarker = DB::connection($name)->select("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [self::MARKER_TABLE]) !== [];
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $identity['configured'] = (string) ($config['database'] ?? '');
                $identity['actual'] = DB::connection($name)->selectOne('SELECT DATABASE() AS d')->d;
                $identity['server'] = DB::connection($name)->selectOne('SELECT @@server_uuid AS u')->u ?? null;
                $hasMarker = DB::connection($name)->selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [self::MARKER_TABLE])->n > 0;
            } else {
                return array_merge($identity, ['error' => "unsupported driver {$driver}"]);
            }
            if ($hasMarker) {
                $identity['markers'] = DB::connection($name)->table(self::MARKER_TABLE)->pluck('database_name')->all();
            }
        } catch (Throwable $e) {
            $identity['error'] = $e->getMessage();
        }

        return $identity;
    }

    private function logRefusal(?string $command, array $verdict): void
    {
        try {
            Log::build(['driver' => 'single', 'path' => storage_path('logs/recipe-guard.log')])
                ->warning('recipe command refused', ['command' => $command, 'reasons' => $verdict['reasons'], 'identity' => $verdict['identity'], 'pid' => getmypid()]);
        } catch (Throwable) {
            // a logging failure never turns a refusal into an execution
        }
    }
}
