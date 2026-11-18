<?php

namespace App\Core\Realtime;

/**
 * Lifetime of one SSE stream (ADR-826 / ADR-827) : output unbuffered, bounded duration, guaranteed unregister.
 *
 *  - ADR-826 — measured on DEV (PHP-FPM, max_execution_time 30) : every stream was KILLED after 30 s and its
 *    unregister never ran (22 993 fatal errors ; 29 541 connects for 259 logged disconnects). The execution
 *    limit follows `stream_timeout` (plus a margin) and the unregister is guaranteed by a shutdown function —
 *    it also runs after a fatal error or when the client has gone (ignore_user_abort keeps control so the loop
 *    sees connection_aborted() and ends normally).
 *  - ADR-827 — measured under a real PHP-FPM pool : a limit locked by the pool
 *    (php_admin_value[max_execution_time]) ignores set_time_limit() ; the stream then ends CLEANLY 5 s before
 *    the effective limit and the browser reconnects as for any normal end of stream.
 */
final class StreamLifetime
{
    private bool $released = false;

    public readonly int $timeout;

    private function __construct(private readonly int $userId, private readonly ?int $companyId, int $timeout)
    {
        $this->timeout = $timeout;
    }

    public static function begin(int $configuredTimeout, int $userId, ?int $companyId): self
    {
        // Disable output buffering for real-time streaming
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level()) {
            ob_end_clean();
        }
        @set_time_limit($configuredTimeout + 15);
        ignore_user_abort(true);
        $lifetime = new self($userId, $companyId, self::effectiveTimeout($configuredTimeout, (int) ini_get('max_execution_time')));
        register_shutdown_function(fn () => $lifetime->release());

        return $lifetime;
    }

    /** Stream duration that ends before the execution limit really in force (0 = unlimited). */
    public static function effectiveTimeout(int $configured, int $executionLimit): int
    {
        return $executionLimit > 0 && $executionLimit < $configured + 5 ? max(5, $executionLimit - 5) : $configured;
    }

    /** Unregister once (normal end, client gone, fatal error). Platform scope is not tracked (ADR-431). */
    public function release(): void
    {
        if ($this->released || $this->companyId === null) {
            return;
        }
        $this->released = true;
        ConnectionTracker::disconnect($this->userId, $this->companyId);
    }
}
