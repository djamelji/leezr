<?php

namespace App\Core\Automation;

use App\Core\Models\Company;
use App\Core\Scopes\CompanyScope;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-437: Company-scoped workflow rule.
 *
 * Trigger → Conditions → Actions, evaluated on domain events.
 * Coexists with the platform scheduler (AutomationRule, ADR-425).
 */
class WorkflowRule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'trigger_topic',
        'trigger_config',
        'conditions',
        'actions',
        'enabled',
        'max_executions_per_day',
        'cooldown_minutes',
        'executions_today',
        'last_executed_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
            'max_executions_per_day' => 'integer',
            'cooldown_minutes' => 'integer',
            'executions_today' => 'integer',
            'last_executed_at' => 'datetime',
        ];
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class);
    }

    /**
     * Scope: only enabled rules matching a given trigger topic.
     */
    public function scopeForTrigger(Builder $query, string $topic): Builder
    {
        return $query->where('enabled', true)->where('trigger_topic', $topic);
    }

    /**
     * Check if this rule can execute (cooldown + daily quota).
     */
    public function canExecute(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        // Daily quota
        if ($this->max_executions_per_day > 0 && $this->executions_today >= $this->max_executions_per_day) {
            return false;
        }

        // Cooldown
        if ($this->cooldown_minutes > 0 && $this->last_executed_at) {
            $cooldownUntil = $this->last_executed_at->addMinutes($this->cooldown_minutes);
            if (now()->lt($cooldownUntil)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record an execution and update counters.
     */
    public function recordExecution(): void
    {
        $this->increment('executions_today');
        $this->update(['last_executed_at' => now()]);
    }
}
