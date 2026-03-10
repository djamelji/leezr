<?php

namespace App\Core\Billing\Dunning;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\PaymentMethodResolver;
use App\Core\Billing\WalletLedger;
use Illuminate\Support\Facades\Log;

/**
 * Payment retry logic for the dunning engine.
 *
 * Handles provider payments, wallet payments, and split payments.
 * All methods run with specific transaction assumptions documented inline.
 */
class DunningRetryStrategy
{
    /**
     * Attempt provider-first payment with fallback across saved payment methods.
     *
     * Runs OUTSIDE DB::transaction to avoid holding row locks during API calls.
     * Returns 'provider_attempted' if any method accepted the charge.
     * Returns null if all methods fail → caller falls back to wallet.
     */
    public static function attemptProviderPayment(Invoice $invoice): ?string
    {
        if ($invoice->amount_due <= 0) {
            return null;
        }

        $subscription = $invoice->subscription;

        if (! $subscription || ! $subscription->provider || $subscription->provider === 'internal') {
            return null;
        }

        $adapter = static::resolveAdapter($subscription->provider);

        if (! $adapter) {
            return null;
        }

        // Resolve all saved payment methods for fallback
        $methods = PaymentMethodResolver::resolveForCompany($invoice->company);

        if ($methods->isEmpty()) {
            // No saved methods — try default customer charge
            try {
                $result = $adapter->collectInvoice($invoice, $invoice->company);

                return $result['status'] === 'succeeded' ? 'provider_attempted' : null;
            } catch (\Throwable) {
                return null;
            }
        }

        // Try each payment method in order (default first)
        foreach ($methods as $method) {
            if (! $method->provider_payment_method_id) {
                continue;
            }

            Log::channel('billing')->info('[billing] fallback payment method attempt', [
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'payment_method_id' => $method->provider_payment_method_id,
                'method_key' => $method->method_key,
                'is_default' => $method->is_default,
            ]);

            try {
                $result = $adapter->chargeInvoiceWithPaymentMethod(
                    $invoice,
                    $method->provider_payment_method_id,
                );

                if ($result['status'] === 'succeeded') {
                    return 'provider_attempted';
                }

                Log::channel('billing')->info('[billing] payment attempt failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'method_key' => $method->method_key,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                Log::channel('billing')->info('[billing] payment attempt failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'method_key' => $method->method_key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null; // All methods failed → wallet fallback
    }

    /**
     * Attempt to pay the invoice using wallet balance (full-coverage).
     * Returns true if fully paid, false otherwise.
     */
    public static function attemptWalletPayment(Invoice $invoice): bool
    {
        $company = $invoice->company;
        $amountDue = $invoice->amount_due;

        if ($amountDue <= 0) {
            return true;
        }

        $walletBalance = WalletLedger::balance($company);

        if ($walletBalance >= $amountDue) {
            WalletLedger::debit(
                company: $company,
                amount: $amountDue,
                sourceType: 'dunning_payment',
                sourceId: $invoice->id,
                description: "Dunning retry payment for invoice {$invoice->number}",
                actorType: 'system',
                idempotencyKey: "dunning-retry-{$invoice->id}-{$invoice->retry_count}",
            );

            return true;
        }

        return false;
    }

    /**
     * ADR-265: Attempt split payment — wallet partial + provider remainder.
     *
     * Runs OUTSIDE transaction to avoid holding row locks during Stripe API calls.
     * Only attempted when wallet has partial balance (0 < balance < amount_due).
     *
     * @return array{wallet_amount: int, provider_payment_id: string, provider_amount: int}|null
     */
    public static function attemptSplitPayment(Invoice $invoice): ?array
    {
        $company = $invoice->company;
        $amountDue = $invoice->amount_due;

        if ($amountDue <= 0) {
            return null;
        }

        $walletBalance = WalletLedger::balance($company);

        // No partial balance, or wallet covers full amount (handled by attemptWalletPayment)
        if ($walletBalance <= 0 || $walletBalance >= $amountDue) {
            return null;
        }

        $subscription = $invoice->subscription;

        if (! $subscription || ! $subscription->provider || $subscription->provider === 'internal') {
            return null;
        }

        $adapter = static::resolveAdapter($subscription->provider);

        if (! $adapter) {
            return null;
        }

        $remainder = $amountDue - $walletBalance;

        // Try each payment method for the remainder amount
        $methods = PaymentMethodResolver::resolveForCompany($company);

        foreach ($methods as $method) {
            if (! $method->provider_payment_method_id) {
                continue;
            }

            Log::channel('billing')->info('[billing] split payment attempt', [
                'invoice_id' => $invoice->id,
                'company_id' => $company->id,
                'wallet_amount' => $walletBalance,
                'provider_remainder' => $remainder,
                'payment_method_id' => $method->provider_payment_method_id,
            ]);

            try {
                $result = $adapter->chargeInvoiceWithPaymentMethod(
                    $invoice,
                    $method->provider_payment_method_id,
                    $remainder,
                );

                if ($result['status'] === 'succeeded') {
                    return [
                        'wallet_amount' => $walletBalance,
                        'provider_payment_id' => $result['provider_payment_id'],
                        'provider_amount' => $remainder,
                    ];
                }

                Log::channel('billing')->info('[billing] split payment provider failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                Log::channel('billing')->info('[billing] split payment provider failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null; // All methods failed
    }

    /**
     * ADR-265: Finalize a split payment — debit wallet + record provider payment + mark paid.
     *
     * Called INSIDE transaction with invoice locked.
     * Returns true if split payment finalized, false if wallet debit failed.
     */
    public static function finalizeSplitPayment(Invoice $invoice, array $splitResult, int $newRetryCount): bool
    {
        // Record provider payment first (it already happened — must not lose it)
        Payment::updateOrCreate(
            ['provider_payment_id' => $splitResult['provider_payment_id']],
            [
                'company_id' => $invoice->company_id,
                'subscription_id' => $invoice->subscription_id,
                'invoice_id' => $invoice->id,
                'amount' => $splitResult['provider_amount'],
                'currency' => $invoice->currency ?? 'EUR',
                'status' => 'succeeded',
                'provider' => $invoice->subscription?->provider ?? 'stripe',
            ],
        );

        // Debit wallet for the partial amount
        try {
            WalletLedger::debit(
                company: $invoice->company,
                amount: $splitResult['wallet_amount'],
                sourceType: 'dunning_split_payment',
                sourceId: $invoice->id,
                description: "Split payment (wallet portion) for invoice {$invoice->number}",
                actorType: 'system',
                idempotencyKey: "dunning-split-{$invoice->id}-{$invoice->retry_count}",
            );
        } catch (\Throwable $e) {
            Log::channel('billing')->error('[billing] Split payment wallet debit failed after provider charged', [
                'invoice_id' => $invoice->id,
                'wallet_amount' => $splitResult['wallet_amount'],
                'provider_amount' => $splitResult['provider_amount'],
                'provider_payment_id' => $splitResult['provider_payment_id'],
                'error' => $e->getMessage(),
            ]);

            // Provider payment is recorded — invoice stays overdue for admin review
            return false;
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'retry_count' => $newRetryCount,
            'next_retry_at' => null,
        ]);

        DunningTransitioner::checkReactivation($invoice->company, $invoice->subscription_id);

        Log::channel('billing')->info('[billing] Split payment succeeded', [
            'invoice_id' => $invoice->id,
            'wallet_amount' => $splitResult['wallet_amount'],
            'provider_amount' => $splitResult['provider_amount'],
        ]);

        return true;
    }

    public static function resolveAdapter(string $provider): ?PaymentProviderAdapter
    {
        return match ($provider) {
            'stripe' => app(StripePaymentAdapter::class),
            default => null,
        };
    }
}
