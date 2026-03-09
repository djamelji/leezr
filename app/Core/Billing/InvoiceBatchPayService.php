<?php

namespace App\Core\Billing;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-257: Batch invoice payment with Stripe Payment Element.
 *
 * Flow:
 * 1. User selects invoices on the payment page
 * 2. createPaymentIntent(): apply wallet credit if enabled, create Stripe PaymentIntent for remainder
 * 3. Frontend mounts Stripe Payment Element → user picks method → stripe.confirmPayment()
 * 4. confirmPayment(): verify PaymentIntent succeeded, distribute payment across invoices
 *
 * Wallet is applied first (partial OK), Stripe covers the remainder.
 * If wallet covers the full amount, invoices are paid immediately (no PaymentIntent needed).
 */
class InvoiceBatchPayService
{
    /**
     * Create a PaymentIntent for the selected invoices (after optional wallet deduction).
     *
     * @return array{mode: string, ...}
     */
    public static function createPaymentIntent(
        Company $company,
        array $invoiceIds,
        bool $useWallet = true,
        ?int $userId = null,
    ): array {
        // Load & validate invoices
        $invoices = Invoice::where('company_id', $company->id)
            ->whereIn('id', $invoiceIds)
            ->whereIn('status', ['open', 'overdue', 'uncollectible'])
            ->where('amount_due', '>', 0)
            ->whereNotNull('finalized_at')
            ->orderBy('due_at')
            ->get();

        if ($invoices->isEmpty()) {
            throw new \RuntimeException('No eligible invoices found.');
        }

        $total = $invoices->sum('amount_due');
        $currency = $invoices->first()->currency ?? WalletLedger::ensureWallet($company)->currency;
        $walletApplied = 0;
        $remaining = $total;

        // Wallet deduction
        if ($useWallet) {
            $walletBalance = WalletLedger::balance($company);
            $walletApplied = min($walletBalance, $total);
            $remaining = $total - $walletApplied;
        }

        // If wallet covers everything → pay immediately
        if ($remaining <= 0) {
            $paidIds = static::applyWalletAndMarkPaid($company, $invoices, $walletApplied, $userId);

            return [
                'mode' => 'wallet_paid',
                'paid_invoice_ids' => $paidIds,
                'total' => $total,
                'wallet_applied' => $walletApplied,
                'remaining' => 0,
            ];
        }

        // Create Stripe PaymentIntent (confirm=false, automatic_payment_methods)
        $adapter = app(StripePaymentAdapter::class);
        $intent = $adapter->createOnSessionPaymentIntent(
            amount: $remaining,
            currency: strtoupper($currency),
            company: $company,
            metadata: [
                'company_id' => (string) $company->id,
                'invoice_ids' => implode(',', $invoices->pluck('id')->all()),
                'wallet_applied' => (string) $walletApplied,
                'type' => 'invoice_batch_pay',
            ],
        );

        // Resolve publishable key for frontend
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $publishableKey = $module?->getStripePublishableKey()
            ?: config('billing.stripe.key');

        return [
            'mode' => 'payment_required',
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
            'publishable_key' => $publishableKey,
            'total' => $total,
            'wallet_applied' => $walletApplied,
            'remaining' => $remaining,
            'currency' => $currency,
            'invoices' => $invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'amount_due' => $inv->amount_due,
                'status' => $inv->status,
            ])->values()->all(),
        ];
    }

    /**
     * Confirm payment after Stripe PaymentIntent succeeds.
     * Distributes the charge across invoices and applies wallet credit.
     *
     * @return array{paid_invoice_ids: int[], total_paid: int}
     */
    public static function confirmPayment(
        Company $company,
        string $paymentIntentId,
        ?int $userId = null,
        bool $saveCard = false,
    ): array {
        $adapter = app(StripePaymentAdapter::class);

        // Verify PaymentIntent via Stripe API
        $intent = $adapter->retrievePaymentIntent($paymentIntentId);

        if (! in_array($intent->status, ['succeeded', 'processing'], true)) {
            throw new \RuntimeException('Payment has not been confirmed yet.');
        }

        $metadata = $intent->metadata?->toArray() ?? [];

        if (($metadata['company_id'] ?? '') !== (string) $company->id) {
            throw new \RuntimeException('Payment intent does not belong to this company.');
        }

        if (($metadata['type'] ?? '') !== 'invoice_batch_pay') {
            throw new \RuntimeException('Invalid payment intent type.');
        }

        $invoiceIds = array_map('intval', explode(',', $metadata['invoice_ids'] ?? ''));
        $walletApplied = (int) ($metadata['wallet_applied'] ?? 0);
        $providerAmount = $intent->amount_received ?? $intent->amount;

        // SEPA / async: payment is processing — defer wallet + distribution to webhook
        if ($intent->status === 'processing') {
            Log::channel('billing')->info('[billing] batch payment processing (async method — deferred)', [
                'company_id' => $company->id,
                'payment_intent_id' => $paymentIntentId,
                'invoice_ids' => $invoiceIds,
            ]);

            // Save card if requested — the PM/mandate is valid regardless of payment outcome
            if ($saveCard && $intent->payment_method) {
                try {
                    static::savePaymentMethod($company, (string) $intent->payment_method, $adapter);
                } catch (\Throwable $e) {
                    Log::channel('billing')->warning('[billing] save card during processing failed', [
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'mode' => 'processing',
                'paid_invoice_ids' => [],
                'total_paid' => 0,
            ];
        }

        $result = DB::transaction(function () use ($company, $invoiceIds, $walletApplied, $providerAmount, $paymentIntentId, $userId, $intent) {
            $invoices = Invoice::where('company_id', $company->id)
                ->whereIn('id', $invoiceIds)
                ->whereIn('status', ['open', 'overdue', 'uncollectible'])
                ->where('amount_due', '>', 0)
                ->orderBy('due_at')
                ->lockForUpdate()
                ->get();

            if ($invoices->isEmpty()) {
                return ['paid_invoice_ids' => [], 'total_paid' => 0];
            }

            $paidInvoiceIds = [];
            $totalPaid = 0;

            // Phase 1: Apply wallet credit (if any)
            $walletRemaining = $walletApplied;
            if ($walletRemaining > 0) {
                foreach ($invoices as $invoice) {
                    if ($walletRemaining <= 0) {
                        break;
                    }

                    $walletPayment = min($walletRemaining, $invoice->amount_due);

                    WalletLedger::debit(
                        company: $company,
                        amount: $walletPayment,
                        sourceType: 'batch_pay',
                        sourceId: $invoice->id,
                        description: "Batch pay: invoice {$invoice->number}",
                        actorType: $userId ? 'user' : 'system',
                        actorId: $userId,
                        idempotencyKey: "batch-pay-wallet-{$paymentIntentId}-inv-{$invoice->id}",
                    );

                    $walletRemaining -= $walletPayment;

                    $invoice->update([
                        'wallet_credit_applied' => $invoice->wallet_credit_applied + $walletPayment,
                        'amount_due' => $invoice->amount_due - $walletPayment,
                    ]);

                    $invoice->refresh();
                }
            }

            // Phase 2: Distribute provider payment across invoices
            $providerRemaining = $providerAmount;
            foreach ($invoices as $invoice) {
                if ($providerRemaining <= 0 || $invoice->amount_due <= 0) {
                    // If amount_due is 0 from wallet phase, mark paid
                    if ($invoice->amount_due <= 0 && in_array($invoice->status, ['open', 'overdue', 'uncollectible'])) {
                        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                        $paidInvoiceIds[] = $invoice->id;
                        $totalPaid += $invoice->amount;
                    }

                    continue;
                }

                $invoicePayment = min($providerRemaining, $invoice->amount_due);

                // Create Payment record (suffixed per invoice for unique constraint)
                Payment::updateOrCreate(
                    ['provider_payment_id' => "{$paymentIntentId}-inv-{$invoice->id}"],
                    [
                        'invoice_id' => $invoice->id,
                        'company_id' => $company->id,
                        'subscription_id' => $invoice->subscription_id,
                        'amount' => $invoicePayment,
                        'currency' => $invoice->currency,
                        'status' => 'succeeded',
                        'provider' => 'stripe',
                    ],
                );

                $providerRemaining -= $invoicePayment;

                $invoice->update([
                    'amount_due' => $invoice->amount_due - $invoicePayment,
                    'provider_payment_id' => $paymentIntentId,
                ]);

                $invoice->refresh();

                if ($invoice->amount_due <= 0) {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                    $paidInvoiceIds[] = $invoice->id;
                    $totalPaid += $invoice->amount;
                }
            }

            // Phase 3: Reactivation check
            if (! empty($paidInvoiceIds)) {
                $subscriptionIds = $invoices->pluck('subscription_id')->filter()->unique();
                foreach ($subscriptionIds as $subId) {
                    DunningEngine::checkReactivation($company, $subId);
                }
            }

            Log::channel('billing')->info('[billing] batch payment confirmed', [
                'company_id' => $company->id,
                'payment_intent_id' => $paymentIntentId,
                'paid_invoice_ids' => $paidInvoiceIds,
                'total_paid' => $totalPaid,
                'wallet_applied' => $walletApplied,
                'provider_amount' => $providerAmount,
            ]);

            return [
                'mode' => 'paid',
                'paid_invoice_ids' => $paidInvoiceIds,
                'total_paid' => $totalPaid,
            ];
        });

        // Save payment method if requested (outside transaction — non-critical)
        if ($saveCard && $intent->payment_method) {
            try {
                static::savePaymentMethod($company, (string) $intent->payment_method, $adapter);
            } catch (\Throwable $e) {
                Log::channel('billing')->warning('[billing] save card after batch pay failed', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Pay invoices using wallet credit only (when wallet covers the full amount).
     */
    private static function applyWalletAndMarkPaid(
        Company $company,
        $invoices,
        int $walletAmount,
        ?int $userId,
    ): array {
        return DB::transaction(function () use ($company, $invoices, $walletAmount, $userId) {
            $invoices = Invoice::where('company_id', $company->id)
                ->whereIn('id', $invoices->pluck('id'))
                ->whereIn('status', ['open', 'overdue', 'uncollectible'])
                ->orderBy('due_at')
                ->lockForUpdate()
                ->get();

            $paidIds = [];
            $walletRemaining = $walletAmount;

            foreach ($invoices as $invoice) {
                if ($walletRemaining <= 0) {
                    break;
                }

                $payment = min($walletRemaining, $invoice->amount_due);

                WalletLedger::debit(
                    company: $company,
                    amount: $payment,
                    sourceType: 'batch_pay',
                    sourceId: $invoice->id,
                    description: "Batch pay (wallet): invoice {$invoice->number}",
                    actorType: $userId ? 'user' : 'system',
                    actorId: $userId,
                    idempotencyKey: "batch-pay-wallet-only-inv-{$invoice->id}-" . now()->timestamp,
                );

                $walletRemaining -= $payment;

                $invoice->update([
                    'wallet_credit_applied' => $invoice->wallet_credit_applied + $payment,
                    'amount_due' => $invoice->amount_due - $payment,
                ]);

                $invoice->refresh();

                if ($invoice->amount_due <= 0) {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                    $paidIds[] = $invoice->id;
                }
            }

            // Reactivation check
            if (! empty($paidIds)) {
                $subscriptionIds = $invoices->pluck('subscription_id')->filter()->unique();
                foreach ($subscriptionIds as $subId) {
                    DunningEngine::checkReactivation($company, $subId);
                }
            }

            return $paidIds;
        });
    }

    /**
     * Save the payment method used during batch pay to the company's payment profiles.
     * Reuses the same extraction logic as CompanyPaymentSetupController.
     */
    private static function savePaymentMethod(Company $company, string $paymentMethodId, StripePaymentAdapter $adapter): void
    {
        $pm = $adapter->retrievePaymentMethod($paymentMethodId);

        $type = $pm->type ?? 'card';
        if ($type === 'sepa_debit') {
            $sepa = $pm->sepa_debit;
            $methodKey = 'sepa_debit';
            $label = 'SEPA •••• ' . ($sepa?->last4 ?? '****');
            $fingerprint = $sepa?->fingerprint;
            $meta = ['type' => 'sepa_debit', 'bank_code' => $sepa?->bank_code, 'country' => $sepa?->country, 'last4' => $sepa?->last4 ?? '****', 'fingerprint' => $fingerprint, 'holder_name' => $pm->billing_details?->name];
        } else {
            $card = $pm->card ?? null;
            $methodKey = 'card';
            $label = ucfirst($card?->brand ?? 'unknown') . ' •••• ' . ($card?->last4 ?? '****');
            $fingerprint = $card?->fingerprint;
            $meta = ['brand' => $card?->brand ?? 'unknown', 'last4' => $card?->last4 ?? '****', 'exp_month' => $card?->exp_month, 'exp_year' => $card?->exp_year, 'fingerprint' => $fingerprint, 'country' => $card?->country, 'funding' => $card?->funding];
        }

        // Deduplicate by fingerprint
        if ($fingerprint) {
            $existing = CompanyPaymentProfile::where('company_id', $company->id)
                ->where('provider_key', 'stripe')
                ->get()
                ->first(fn ($p) => ($p->metadata['fingerprint'] ?? null) === $fingerprint);

            if ($existing) {
                return; // Already saved
            }
        }

        CompanyPaymentProfile::where('company_id', $company->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        CompanyPaymentProfile::updateOrCreate(
            ['company_id' => $company->id, 'provider_key' => 'stripe', 'provider_payment_method_id' => $paymentMethodId],
            ['method_key' => $methodKey, 'label' => $label, 'is_default' => true, 'metadata' => $meta],
        );
    }
}
