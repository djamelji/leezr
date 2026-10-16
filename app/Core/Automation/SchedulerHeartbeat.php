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

    protected $fillable = ['beat_at', 'installed_at', 'environment'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime', 'installed_at' => 'datetime'];
    }

    /** The singleton heartbeat row, creating it (with an install anchor) if absent. */
    public static function singleton(): self
    {
        $row = static::query()->first();

        if (! $row) {
            $row = static::create([
                'beat_at' => null,
                'installed_at' => now(),
                'environment' => app()->environment(),
            ]);
        }

        return $row;
    }

    public static function pulse(): void
    {
        $row = static::singleton();
        $row->beat_at = now();
        $row->installed_at = $row->installed_at ?? now();
        $row->environment = app()->environment();
        $row->save();
    }

    public static function lastBeatAt(): ?Carbon
    {
        return static::query()->first()?->beat_at;
    }
}
