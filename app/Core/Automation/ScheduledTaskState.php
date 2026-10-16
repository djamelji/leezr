<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Persisted enable/disable state for a scheduled task.
 *
 * Source of truth for whether a task is allowed to run. Absence of a row means the
 * task is enabled (default). Disabling is REAL: the scheduler skips the command via a
 * ->when() filter (routes/console.php) and manual run is refused — never a cosmetic
 * status change.
 */
class ScheduledTaskState extends Model
{
    protected $fillable = [
        'task',
        'is_enabled',
        'disabled_by',
        'disabled_reason',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    private const CACHE_PREFIX = 'scheduled_task_state:';

    /**
     * Is the task allowed to run? Default true when no state row exists.
     * Cached briefly so the per-minute scheduler does not hit the DB for every task.
     */
    public static function isEnabled(string $task): bool
    {
        return Cache::remember(
            self::CACHE_PREFIX.$task,
            now()->addSeconds(30),
            fn () => (bool) (static::query()->where('task', $task)->value('is_enabled') ?? true)
        );
    }

    /**
     * Enable or disable a task and invalidate the cache immediately so the next
     * scheduler tick and the cockpit reflect the change without delay.
     */
    public static function setEnabled(string $task, bool $enabled, ?string $actor = null, ?string $reason = null): self
    {
        $state = static::updateOrCreate(
            ['task' => $task],
            [
                'is_enabled' => $enabled,
                'disabled_by' => $enabled ? null : $actor,
                'disabled_reason' => $enabled ? null : $reason,
                'disabled_at' => $enabled ? null : now(),
            ],
        );

        Cache::forget(self::CACHE_PREFIX.$task);

        return $state;
    }

    /** @return array<string, bool> task => is_enabled, for tasks that have an explicit row */
    public static function map(): array
    {
        return static::query()->pluck('is_enabled', 'task')->map(fn ($v) => (bool) $v)->all();
    }
}
