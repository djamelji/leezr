<?php

namespace App\Core\Billing;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Adapters\StripePaymentAdapter;

/**
 * ADR-140/141: Drift detection between Stripe and local database.
 *
 * Compares Stripe PaymentIntents with local Payment, Invoice, and CreditNote records.
 * Optionally auto-repairs safe drift types (D3e) via AutoRepairEngine.
 *
 * Drift types:
 *   - missing_local_payment: Stripe succeeded, no local Payment
 *   - missing_stripe_payment: Local Payment succeeded, not in Stripe
 *   - status_mismatch: Stripe succeeded, local Payment status != succeeded
 *   - refund_mismatch: Stripe charge refunded, no matching CreditNote
 *   - invoice_not_paid: Stripe succeeded + invoice_id, but Invoice not paid
 */
class ReconciliationEngine
{
    /**
     * Reconcile Stripe payments with local state.
     *
     * @param  int|null  $companyId  Limit to a single company (null = all Stripe companies)
     * @param  bool  $dryRun  If true, skip audit logging and auto-repair mutations
     * @param  bool  $autoRepair  If true, attempt auto-repair of safe drift types (ADR-141)
     * @param  \Carbon\Carbon|null  $since  Incremental: only reconcile since this timestamp (ADR-318)
     * @return array{drifts: array, summary: array{total: int, by_type: array}, repairs?: array}
     */
    public static function reconcile(?int $companyId = null, bool $dryRun = false, bool $autoRepair = false, ?\Carbon\Carbon $since = null): array
    {
        $adapter = app(StripePaymentAdapter::class);
        $allDrifts = [];

        // Find companies with Stripe payment customers
        $query = CompanyPaymentCustomer::where('provider_key', 'stripe');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $customers = $query->get();

        foreach ($customers as $customer) {
            // ADR-318: Incremental reconciliation — use last_reconciled_at if no explicit $since
            $sinceTimestamp = $since
                ? $since->timestamp
                : ($customer->last_reconciled_at
                    ? $customer->last_reconciled_at->timestamp
                    : now()->subDays(30)->timestamp);

            try {
                $stripeIntents = $adapter->listPaymentIntents($customer->company_id, $sinceTimestamp);
            } catch (\Throwable) {
                // Rate limit or API error — skip this company
                continue;
            }

            $drifts = static::detectDrifts($customer->company_id, $stripeIntents);
            $allDrifts = array_merge($allDrifts, $drifts);

            // ADR-318: Update last_reconciled_at after successful reconciliation
            if (! $dryRun) {
                $customer->update(['last_reconciled_at' => now()]);
            }
        }

        // Build summary
        $byType = [];

        foreach ($allDrifts as $drift) {
            $byType[$drift['type']] = ($byType[$drift['type']] ?? 0) + 1;
        }

        $summary = [
            'total' => count($allDrifts),
            'by_type' => $byType,
        ];

        // Audit log if not dry-run and drifts found
        if (! $dryRun && count($allDrifts) > 0) {
            app(AuditLogger::class)->logPlatform(
                AuditAction::BILLING_DRIFT_DETECTED,
                'reconciliation',
                null,
                [
                    'severity' => 'critical',
                    'actorType' => 'system',
                    'metadata' => [
                        'summary' => $summary,
                        'drift_count' => count($allDrifts),
                        'companies_checked' => $customers->count(),
                    ],
                ],
            );
        }

        $result = ['drifts' => $allDrifts, 'summary' => $summary];

        // Auto-repair safe drifts if enabled (ADR-141 D3e)
        if ($autoRepair && config('billing.auto_repair.enabled') && count($allDrifts) > 0) {
            $result['repairs'] = AutoRepairEngine::repair($allDrifts, $dryRun);
        }

        return $result;
    }

    /**
     * Detect drifts for a single company.
     */
    private static function detectDrifts(int $companyId, array $stripeIntents): array
    {
        $drifts = [];

        // Index local payments by provider_payment_id (last 30 days, provider=stripe)
        $localPayments = Payment::where('company_id', $companyId)
            ->where('provider', 'stripe')
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->keyBy('provider_payment_id');

        // Index Stripe intents by ID
        $stripeById = collect($stripeIntents)->keyBy('id');

        // 1. Missing local payment: Stripe succeeded, no local Payment
        foreach ($stripeIntents as $intent) {
            if ($intent['status'] !== 'succeeded') {
                continue;
            }

            if (! $localPayments->has($intent['id'])) {
                $drifts[] = [
                    'type' => 'missing_local_payment',
                    'provider_payment_id' => $intent['id'],
                    'company_id' => $companyId,
                    'details' => [
                        'stripe_amount' => $intent['amount'],
                        'stripe_currency' => $intent['currency'] ?? null,
                        'stripe_status' => $intent['status'],
                    ],
                ];
            }
        }

        // 2. Missing Stripe payment: Local succeeded, not in Stripe list
        foreach ($localPayments as $payment) {
            if ($payment->status !== 'succeeded') {
                continue;
            }

            if ($payment->provider_payment_id && ! $stripeById->has($payment->provider_payment_id)) {
                $drifts[] = [
                    'type' => 'missing_stripe_payment',
                    'provider_payment_id' => $payment->provider_payment_id,
                    'company_id' => $companyId,
                    'details' => [
                        'local_amount' => $payment->amount,
                        'local_status' => $payment->status,
                    ],
                ];
            }
        }

        // 3. Status mismatch: Stripe succeeded but local status != succeeded
        foreach ($stripeIntents as $intent) {
            if ($intent['status'] !== 'succeeded') {
                continue;
            }

            $localPayment = $localPayments->get($intent['id']);

            if ($localPayment && $localPayment->status !== 'succeeded') {
                $drifts[] = [
                    'type' => 'status_mismatch',
                    'provider_payment_id' => $intent['id'],
                    'company_id' => $companyId,
                    'details' => [
                        'stripe_status' => 'succeeded',
                        'local_status' => $localPayment->status,
                    ],
                ];
            }
        }

        // 4. Refund mismatch: Stripe charge refunded but no CreditNote
        foreach ($stripeIntents as $intent) {
            $totalRefunded = 0;

            foreach ($intent['charges'] ?? [] as $charge) {
                $totalRefunded += $charge['amount_refunded'] ?? 0;
            }

            if ($totalRefunded <= 0) {
                continue;
            }

            $localPayment = $localPayments->get($intent['id']);

            if (! $localPayment) {
                continue; // Already caught by missing_local_payment
            }

            // Check if any CreditNote exists for this payment's invoice
            $hasCreditNote = false;

            if ($localPayment->invoice_id) {
                $hasCreditNote = CreditNote::where('invoice_id', $localPayment->invoice_id)
                    ->where('amount', '>', 0)
                    ->exists();
            }

            if (! $hasCreditNote) {
                $drifts[] = [
                    'type' => 'refund_mismatch',
                    'provider_payment_id' => $intent['id'],
                    'company_id' => $companyId,
                    'details' => [
                        'stripe_refunded_amount' => $totalRefunded,
                        'local_credit_notes' => 0,
                    ],
                ];
            }
        }

        // 5. Invoice not paid: Stripe succeeded + metadata.invoice_id, but Invoice not paid
        foreach ($stripeIntents as $intent) {
            if ($intent['status'] !== 'succeeded') {
                continue;
            }

            $invoiceId = $intent['metadata']['invoice_id'] ?? null;

            if (! $invoiceId) {
                continue;
            }

            $invoice = Invoice::find((int) $invoiceId);

            if ($invoice && $invoice->status !== 'paid') {
                $drifts[] = [
                    'type' => 'invoice_not_paid',
                    'provider_payment_id' => $intent['id'],
                    'company_id' => $companyId,
                    'details' => [
                        'invoice_id' => $invoice->id,
                        'invoice_status' => $invoice->status,
                    ],
                ];
            }
        }

        return $drifts;
    }
}
