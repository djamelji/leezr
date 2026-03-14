<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Notifications\Billing\PaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-333: Auto-charge an invoice against the company's default payment method.
 *
 * CRITICAL: MUST be called OUTSIDE any DB transaction.
 * Stripe API calls inside a transaction risk charging the provider but rolling back the DB.
 *
 * Pipeline:
 *   1. Guard: invoice must be open with amount_due > 0
 *   2. Guard: skip if SEPA debit already scheduled (deferred collection)
 *   3. Resolve provider adapter from subscription
 *   4. Call adapter->collectInvoice() (Stripe API)
 *   5. On success: record Payment + mark invoice paid (short transaction)
 *   6. On failure: log warning, leave open for dunning
 */
class InvoiceAutoChargeService
{
    /**
     * Attempt immediate payment for a finalized invoice.
     *
     * @return string 'paid'|'scheduled'|'failed'|'skipped'
     */
    public static function attempt(Invoice $invoice, ?Subscription $subscription = null): string
    {
        // Guard: only open invoices with amount_due > 0
        if ($invoice->status !== 'open' || $invoice->amount_due <= 0) {
            return 'skipped';
        }

        // Guard: skip if SEPA debit was already scheduled
        $hasScheduledDebit = ScheduledDebit::where('invoice_id', $invoice->id)
            ->pending()
            ->exists();

        if ($hasScheduledDebit) {
            return 'scheduled';
        }

        // Resolve subscription if not provided
        if (! $subscription && $invoice->subscription_id) {
            $subscription = Subscription::find($invoice->subscription_id);
        }

        $company = $invoice->company ?? Company::find($invoice->company_id);

        // ADR-336: Resolve provider from subscription, fallback to company payment profile
        $provider = $subscription?->provider;
        $paymentMethodId = null;

        if (! $provider || $provider === 'internal') {
            $defaultProfile = CompanyPaymentProfile::where('company_id', $company->id)
                ->where('is_default', true)
                ->first();

            if (! $defaultProfile) {
                return 'skipped';
            }

            $provider = $defaultProfile->provider_key;
            $paymentMethodId = $defaultProfile->provider_payment_method_id;
        }

        $adapter = PaymentGatewayManager::adapterFor($provider);

        if (! $adapter) {
            return 'skipped';
        }

        try {
            // ADR-336: Use specific PM when resolved from profile, else default customer charge
            $result = $paymentMethodId
                ? $adapter->chargeInvoiceWithPaymentMethod($invoice, $paymentMethodId)
                : $adapter->collectInvoice($invoice, $company, [
                    'auto_charge' => 'true',
                    'subscription_id' => (string) ($subscription->id ?? ''),
                ]);

            if ($result['status'] === 'succeeded') {
                DB::transaction(function () use ($invoice, $company, $subscription, $result, $provider) {
                    Payment::updateOrCreate(
                        ['provider_payment_id' => $result['provider_payment_id']],
                        [
                            'company_id' => $company->id,
                            'subscription_id' => $subscription?->id,
                            'invoice_id' => $invoice->id,
                            'amount' => $result['amount'],
                            'currency' => $invoice->currency ?? 'EUR',
                            'status' => 'succeeded',
                            'provider' => $provider,
                        ],
                    );

                    $invoice->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'amount_due' => 0,
                    ]);
                });

                // Notify payment received (outside transaction)
                try {
                    $owner = $company->owner();
                    $owner?->notify(new PaymentReceived($invoice->fresh()));
                } catch (\Throwable $e) {
                    Log::warning('[auto-charge] Failed to send payment notification', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return 'paid';
            }

            // ADR-335: Record non-exception failure (provider returned non-succeeded status)
            static::recordFailedPayment($invoice, $company, $subscription, $provider, $result['status'] ?? 'unknown');
        } catch (\Throwable $e) {
            // ADR-335: Record exception failure
            static::recordFailedPayment($invoice, $company, $subscription, $provider, $e->getMessage());

            Log::warning('[auto-charge] Provider payment failed', [
                'invoice_id' => $invoice->id,
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }

        return 'failed';
    }

    /**
     * ADR-335: Record a failed payment attempt so it's visible on the invoice detail page.
     * Wrapped in try/catch — recording failure must never break the billing flow.
     */
    private static function recordFailedPayment(
        Invoice $invoice,
        Company $company,
        ?Subscription $subscription,
        string $provider,
        string $reason,
    ): void {
        try {
            Payment::create([
                'company_id' => $company->id,
                'subscription_id' => $subscription?->id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount_due,
                'currency' => $invoice->currency ?? 'EUR',
                'status' => 'failed',
                'provider' => $provider,
                'provider_payment_id' => "failed-{$invoice->id}-" . now()->timestamp,
                'metadata' => ['error' => mb_substr($reason, 0, 255)],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[auto-charge] Could not record failed payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
