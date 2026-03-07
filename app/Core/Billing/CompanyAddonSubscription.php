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
        return $query->whereNull('deactivated_at');
    }
}
