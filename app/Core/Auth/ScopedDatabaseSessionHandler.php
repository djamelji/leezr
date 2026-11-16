<?php

namespace App\Core\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-801.1 — database session handler that records EACH identity in ITS OWN column.
 *
 * Laravel's stock handler writes `sessions.user_id = Auth::guard()->id()`, i.e. the id of whatever guard
 * is the DEFAULT at write time. `auth:platform` calls `shouldUse('platform')`, so a PlatformUser id was
 * written into `sessions.user_id` (FK → users.id) : SQL 1452 when that number does not exist in `users`
 * (Platform login impossible), and a SILENT link to an unrelated Company user when it does.
 *
 * Here : `user_id` ← `web` guard (users), `platform_user_id` ← `platform` guard (platform_users), each read
 * from its own login key in the session (no DB lookup, no "remember me" side effect), each with its own
 * foreign key. Both are NULL when the scope is not logged in. Other session drivers are untouched (they
 * never store identities).
 */
class ScopedDatabaseSessionHandler extends DatabaseSessionHandler
{
    /** guard name => sessions column */
    public const COLUMNS = ['web' => 'user_id', 'platform' => 'platform_user_id'];

    private static ?bool $hasPlatformColumn = null;

    protected function addUserInformation(&$payload)
    {
        if (! $this->container || ! $this->container->bound('auth') || ! $this->container->bound('session')) {
            return $this;
        }

        // The store StartSession is writing (the manager's current default driver), never a stale singleton.
        $store = $this->container->make('session')->driver();
        foreach (self::COLUMNS as $guardName => $column) {
            if ($column === 'platform_user_id' && ! self::hasPlatformColumn($this->connection, $this->table)) {
                continue; // migration not applied yet : behave like the stock handler for this column
            }
            $guard = $this->container->make('auth')->guard($guardName);
            $payload[$column] = $guard instanceof SessionGuard ? ($store->get($guard->getName()) ?: null) : null;
        }

        return $this;
    }

    private static function hasPlatformColumn($connection, string $table): bool
    {
        return self::$hasPlatformColumn ??= Schema::connection($connection->getName())->hasColumn($table, 'platform_user_id');
    }

    /** Tests only : the column cache is per-process. */
    public static function flushColumnCache(): void
    {
        self::$hasPlatformColumn = null;
    }
}
