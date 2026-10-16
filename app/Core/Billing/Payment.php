<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Payment extends Model
{
    use BelongsToCompany;

    /**
     * F1: post CASH ← AR to the double-entry ledger for EVERY succeeded payment,
     * not only the Stripe webhook path. Payment rows represent external/manual cash
     * (stripe/internal/offline/sepa); wallet settlements live in the wallet ledger and
     * never create a Payment row, so this never double-counts wallet credit.
     *
     * Idempotent (LedgerService F4 guard, keyed on payment id) → safe alongside the
     * explicit Stripe call and any webhook replay. Never throws: a ledger failure is
     * logged and must not break the payment flow (ADR-142 D3f contract).
     */
    protected static function booted(): void
    {
        static::saved(function (Payment $payment): void {
            if ($payment->status !== 'succeeded' || ! $payment->company_id || ! $payment->invoice_id) {
                return;
            }

            if (! $payment->wasRecentlyCreated && ! $payment->wasChanged('status')) {
                return;
            }

            try {
                LedgerService::recordPaymentReceived($payment);
            } catch (\Throwable $e) {
                Log::channel('billing')->error('Ledger payment posting failed (non-fatal)', [
                    'payment_id' => $payment->id,
                    'company_id' => $payment->company_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected $fillable = [
        'subscription_id', 'invoice_id', 'company_id', 'amount', 'currency',
        'status', 'provider', 'provider_payment_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
