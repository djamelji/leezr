<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-220: Tracks addon module subscriptions per company.
 *
 * One row per (company_id, module_key) — UNIQUE constraint.
 * Reactivation = UPDATE deactivated_at = null, activated_at = now().
 */
class CompanyAddonSubscription extends Model
{
    protected $fillable = [
        'company_id',
        'module_key',
        'interval',
        'amount_cents',
        'currency',
        'activated_at',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('deactivated_at')
                ->orWhere('deactivated_at', '>', now());
        });
    }

    public function scopePendingDeactivation(Builder $query): Builder
    {
        return $query->whereNotNull('deactivated_at')
            ->where('deactivated_at', '>', now());
    }

    /**
     * ADR-341: Calculate the period end date for this addon subscription.
     */
    public function periodEnd(): ?\Carbon\Carbon
    {
        if (! $this->activated_at) {
            return null;
        }

        return $this->interval === 'yearly'
            ? $this->activated_at->copy()->addYear()
            : $this->activated_at->copy()->addMonth();
    }

    /**
     * ADR-341: Calculate prorated credit in cents for immediate deactivation.
     *
     * Formula: amount_cents × (remaining_days / total_period_days)
     * Returns 0 if period already ended or no activated_at.
     */
    public function proratedCreditCents(): int
    {
        $periodEnd = $this->periodEnd();
        if (! $periodEnd || $periodEnd->lte(now())) {
            return 0;
        }

        $periodStart = $this->activated_at;
        $totalDays = $periodStart->diffInDays($periodEnd);
        if ($totalDays <= 0) {
            return 0;
        }

        $remainingDays = now()->diffInDays($periodEnd, false);
        if ($remainingDays <= 0) {
            return 0;
        }

        return (int) round($this->amount_cents * $remainingDays / $totalDays);
    }
}
