<?php

namespace App\Core\Workforce\Dsn;

use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * Service for managing DSN submission locks.
 *
 * Prevents concurrent submission of the same declaration.
 * Locks expire after a configurable TTL (default 5 minutes).
 *
 * Race-condition safe: uses try/catch on unique constraint
 * violation instead of check-then-insert (TOCTOU).
 *
 * Sprint 6.8 — ADR-526, Sprint 6.9 — ADR-527
 */
class DsnSubmissionLockService
{
    private const DEFAULT_TTL_SECONDS = 300; // 5 minutes

    /**
     * Acquire a submission lock for a declaration.
     *
     * Thread-safe: if two threads race to acquire, one wins
     * (INSERT succeeds) and the other gracefully returns false
     * (unique constraint caught).
     *
     * @return bool true if lock was acquired, false if already locked
     */
    public function acquire(int $declarationId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        // Clean expired locks first
        $this->cleanExpired();

        // Delete any expired lock for this declaration specifically
        DsnSubmissionLock::where('declaration_id', $declarationId)
            ->where('expires_at', '<', Carbon::now())
            ->delete();

        // Check if an active lock exists
        $existing = DsnSubmissionLock::where('declaration_id', $declarationId)->first();

        if ($existing && $existing->expires_at->isFuture()) {
            return false;
        }

        // Try to create — unique constraint on declaration_id
        // prevents TOCTOU race condition
        try {
            DsnSubmissionLock::create([
                'declaration_id' => $declarationId,
                'locked_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addSeconds($ttlSeconds),
            ]);

            return true;
        } catch (QueryException $e) {
            // Unique constraint violation = another thread won the race
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e; // Re-throw non-unique errors
        }
    }

    /**
     * Release a submission lock.
     */
    public function release(int $declarationId): void
    {
        DsnSubmissionLock::where('declaration_id', $declarationId)->delete();
    }

    /**
     * Check if a declaration is currently locked.
     */
    public function isLocked(int $declarationId): bool
    {
        $lock = DsnSubmissionLock::where('declaration_id', $declarationId)->first();

        return $lock && $lock->expires_at->isFuture();
    }

    /**
     * Clean up expired locks.
     */
    public function cleanExpired(): int
    {
        return DsnSubmissionLock::where('expires_at', '<', Carbon::now())->delete();
    }

    /**
     * Detect unique constraint violation across DB drivers.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        // SQLite: SQLSTATE[23000] Integrity constraint violation
        // MySQL: SQLSTATE[23000] 1062 Duplicate entry
        // PostgreSQL: SQLSTATE[23505] unique_violation
        return in_array($code, ['23000', '23505'], true);
    }
}
