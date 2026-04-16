<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-228: Expected confirmation tracker.
 *
 * When we initiate a Stripe action (checkout, collect, setup),
 * we expect a webhook confirmation. If it doesn't arrive within
 * the expected window, billing:recover-webhooks polls Stripe.
 */
class BillingExpectedConfirmation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'provider_key', 'expected_event_type',
        'provider_reference', 'status', 'expected_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_by' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->expected_by && $this->expected_by->isPast();
    }
}
