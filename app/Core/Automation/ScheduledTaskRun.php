<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'output',
        'error',
        'environment',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    // ── Scopes ────────────────────────────────────────────

    public function scopeTask(Builder $query, string $name): Builder
    {
        return $query->where('task', $name);
    }

    public function scopeLast24h(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
}
