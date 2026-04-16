<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-229: Local tracking of Stripe Checkout Sessions.
 *
 * Enables triple recovery: webhook + UI polling + cron.
 */
class BillingCheckoutSession extends Model
{
    use BelongsToCompany;

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
