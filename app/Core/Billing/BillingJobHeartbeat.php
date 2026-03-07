<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-233: Job heartbeat tracking for billing cron commands.
 *
 * Records last start/finish time, status, and stats for each
 * billing command to enable ops monitoring and catch-up detection.
 */
class BillingJobHeartbeat extends Model
{
    protected $primaryKey = 'job_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'job_key', 'last_started_at', 'last_finished_at',
        'last_status', 'last_error', 'last_run_stats',
    ];

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
            'last_run_stats' => 'array',
        ];
    }

    /**
     * Record that a job has started.
     */
    public static function start(string $key): self
    {
        return static::updateOrCreate(
            ['job_key' => $key],
            ['last_started_at' => now(), 'last_status' => null, 'last_error' => null],
        );
    }

    /**
     * Record that a job has finished.
     */
    public static function finish(string $key, string $status = 'ok', ?array $stats = null, ?string $error = null): void
    {
        static::updateOrCreate(
            ['job_key' => $key],
            [
                'last_finished_at' => now(),
                'last_status' => $status,
                'last_run_stats' => $stats,
                'last_error' => $error,
            ],
        );
    }
}
