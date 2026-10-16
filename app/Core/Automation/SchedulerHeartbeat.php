<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Liveness heartbeat for the Laravel scheduler. A single row whose `beat_at` is
 * refreshed every minute by `scheduler:heartbeat` (run by cron schedule:run).
 *
 * This is the authoritative signal that the cron is actually firing — independent
 * of business-task history, which only runs every few minutes and is wiped on a
 * staging reset. Absence of a heartbeat means "not observed yet" (unknown), NOT ok.
 */
class SchedulerHeartbeat extends Model
{
    public $timestamps = false;

    protected $fillable = ['beat_at', 'environment'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }

    public static function pulse(): void
    {
        $row = static::query()->first() ?? new static();
        $row->beat_at = now();
        $row->environment = app()->environment();
        $row->save();
    }

    public static function lastBeatAt(): ?Carbon
    {
        return static::query()->first()?->beat_at;
    }
}
