<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent plan-change intent — records WHY, WHEN, and HOW a plan change will happen.
 *
 * Invariants:
 *   - Only 1 scheduled intent per company at any time
 *   - Executed intents are immutable (status + executed_at frozen)
 *   - Proration snapshot is set at scheduling time (deterministic)
 */
class PlanChangeIntent extends Model
{
    protected $fillable = [
        'company_id', 'from_plan_key', 'to_plan_key',
        'interval_from', 'interval_to',
        'timing', 'effective_at', 'proration_snapshot',
        'status', 'executed_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'executed_at' => 'datetime',
            'proration_snapshot' => 'array',
        ];
    }

    // ─── Relations ──────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Scopes ─────────────────────────────────────────────

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeExecuted(Builder $query): Builder
    {
        return $query->where('status', 'executed');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->scheduled()->where('effective_at', '<=', now());
    }

    // ─── State checks ───────────────────────────────────────

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isExecuted(): bool
    {
        return $this->status === 'executed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isUpgrade(): bool
    {
        return \App\Core\Plans\PlanRegistry::level($this->to_plan_key)
            > \App\Core\Plans\PlanRegistry::level($this->from_plan_key);
    }

    public function isDowngrade(): bool
    {
        return \App\Core\Plans\PlanRegistry::level($this->to_plan_key)
            < \App\Core\Plans\PlanRegistry::level($this->from_plan_key);
    }
}
