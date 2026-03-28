<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'category',
        'enabled',
        'schedule',
        'config',
        'last_run_at',
        'next_run_at',
        'last_status',
        'last_error',
        'last_run_duration_ms',
        'last_run_actions',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function runLogs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class);
    }

    /**
     * Scope: only enabled rules.
     */
    public function scopeActive($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope: filter by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
