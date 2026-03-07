<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-229: Local tracking of Stripe Checkout Sessions.
 *
 * Enables triple recovery: webhook + UI polling + cron.
 */
class BillingCheckoutSession extends Model
{
    protected $fillable = [
        'company_id', 'subscription_id', 'provider_key',
        'provider_session_id', 'status', 'completed_at',
        'last_checked_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isCreated(): bool
    {
        return $this->status === 'created';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
