<?php

namespace App\Core\Billing;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR-141 D3e: Controlled auto-repair for safe drift types.
 *
 * Repairs 3 drift types:
 *   - missing_local_payment: create Payment from Stripe data
 *   - status_mismatch: update Payment status to match Stripe
 *   - invoice_not_paid: mark Invoice as paid
 *
 * Every mutation takes a FinancialSnapshot BEFORE modifying data.
 * Idempotent: re-running on the same drift produces no duplicate mutations.
 */
class AutoRepairEngine
{
    /**
     * Attempt to repair a list of drifts.
     *
     * @param  array  $drifts  Drift entries from ReconciliationEngine
     * @param  bool  $dryRun  If true, compute what would be repaired but don't mutate
     * @return array{repaired: array, skipped: array, errors: array}
     */
    public static function repair(array $drifts, bool $dryRun = false): array
    {
        $safeTypes = config('auto_repair.safe_types', config('billing.auto_repair.safe_types', [
            'missing_local_payment',
            'status_mismatch',
            'invoice_not_paid',
        ]));

        $repaired = [];
        $skipped = [];
        $errors = [];
        $correlationId = Str::uuid()->toString();

        foreach ($drifts as $drift) {
            if (! in_array($drift['type'], $safeTypes)) {
                $skipped[] = [
                    'drift' => $drift,
                    'reason' => 'unsafe_type',
                ];

                continue;
            }

            try {
                $result = match ($drift['type']) {
                    'missing_local_payment' => static::repairMissingLocalPayment($drift, $dryRun, $correlationId),
                    'status_mismatch' => static::repairStatusMismatch($drift, $dryRun, $correlationId),
                    'invoice_not_paid' => static::repairInvoiceNotPaid($drift, $dryRun, $correlationId),
                    default => null,
                };

                if ($result === null) {
                    $skipped[] = ['drift' => $drift, 'reason' => 'no_strategy'];

                    continue;
                }

                $repaired[] = $result;
            } catch (\Throwable $e) {
                Log::warning('[auto-repair] repair failed', [
                    'drift_type' => $drift['type'],
                    'provider_payment_id' => $drift['provider_payment_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = [
                    'drift' => $drift,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Audit log if any repairs were actually applied
        if (! $dryRun && count($repaired) > 0) {
            try {
                app(AuditLogger::class)->logPlatform(
                    AuditAction::BILLING_AUTO_REPAIR_APPLIED,
                    'auto_repair',
                    null,
                    [
                        'severity' => 'warning',
                        'actorType' => 'system',
                        'correlationId' => $correlationId,
                        'metadata' => [
                            'repairs_count' => count($repaired),
                            'skipped_count' => count($skipped),
                            'errors_count' => count($errors),
                            'correlation_id' => $correlationId,
                            'types' => array_count_values(array_column($repaired, 'type')),
                        ],
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('[auto-repair] audit log failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'repaired' => $repaired,
            'skipped' => $skipped,
            'errors' => $errors,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * Repair missing_local_payment: create Payment from Stripe PI data.
     * Idempotent: if Payment already exists with this provider_payment_id, skip.
     */
    private static function repairMissingLocalPayment(array $drift, bool $dryRun, string $correlationId): array
    {
        $providerPaymentId = $drift['provider_payment_id'];
        $companyId = $drift['company_id'];
        $details = $drift['details'] ?? [];

        // Idempotency check
        $existing = Payment::where('provider_payment_id', $providerPaymentId)->first();

        if ($existing) {
            return [
                'type' => 'missing_local_payment',
                'action' => 'skipped_idempotent',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        if ($dryRun) {
            return [
                'type' => 'missing_local_payment',
                'action' => 'would_create_payment',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        return DB::transaction(function () use ($providerPaymentId, $companyId, $details, $correlationId) {
            // Resolve subscription + invoice from metadata if possible
            $invoiceId = $details['stripe_metadata']['invoice_id'] ?? null;
            $subscription = Subscription::where('company_id', $companyId)
                ->where('provider', 'stripe')
                ->latest()
                ->first();

            // Snapshot: no existing entity — snapshot the "absence" with drift details
            FinancialSnapshot::create([
                'company_id' => $companyId,
                'trigger' => 'auto_repair',
                'drift_type' => 'missing_local_payment',
                'entity_type' => 'payment',
                'entity_id' => $providerPaymentId,
                'snapshot_data' => [
                    'state' => 'absent',
                    'stripe_data' => $details,
                ],
                'correlation_id' => $correlationId,
                'created_at' => now(),
            ]);

            $payment = Payment::create([
                'company_id' => $companyId,
                'subscription_id' => $subscription?->id,
                'invoice_id' => $invoiceId ? (int) $invoiceId : null,
                'amount' => $details['stripe_amount'] ?? 0,
                'currency' => strtoupper($details['stripe_currency'] ?? config('billing.default_currency', 'EUR')),
                'status' => 'succeeded',
                'provider' => 'stripe',
                'provider_payment_id' => $providerPaymentId,
                'metadata' => [
                    'auto_repaired' => true,
                    'correlation_id' => $correlationId,
                ],
            ]);

            return [
                'type' => 'missing_local_payment',
                'action' => 'created_payment',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
                'entity_id' => $payment->id,
            ];
        });
    }

    /**
     * Repair status_mismatch: update local Payment status to match Stripe.
     * Idempotent: if already succeeded, skip.
     */
    private static function repairStatusMismatch(array $drift, bool $dryRun, string $correlationId): array
    {
        $providerPaymentId = $drift['provider_payment_id'];
        $companyId = $drift['company_id'];
        $details = $drift['details'] ?? [];

        $payment = Payment::where('provider_payment_id', $providerPaymentId)->first();

        if (! $payment) {
            return [
                'type' => 'status_mismatch',
                'action' => 'skipped_no_payment',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        // Idempotency: already matches
        if ($payment->status === 'succeeded') {
            return [
                'type' => 'status_mismatch',
                'action' => 'skipped_idempotent',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        if ($dryRun) {
            return [
                'type' => 'status_mismatch',
                'action' => 'would_update_status',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
                'from' => $payment->status,
                'to' => 'succeeded',
            ];
        }

        return DB::transaction(function () use ($payment, $providerPaymentId, $companyId, $details, $correlationId) {
            // Snapshot before mutation
            FinancialSnapshot::create([
                'company_id' => $companyId,
                'trigger' => 'auto_repair',
                'drift_type' => 'status_mismatch',
                'entity_type' => 'payment',
                'entity_id' => (string) $payment->id,
                'snapshot_data' => [
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'provider_payment_id' => $payment->provider_payment_id,
                    'metadata' => $payment->metadata,
                ],
                'correlation_id' => $correlationId,
                'created_at' => now(),
            ]);

            $oldStatus = $payment->status;
            $payment->update([
                'status' => 'succeeded',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'auto_repaired' => true,
                    'previous_status' => $oldStatus,
                    'correlation_id' => $correlationId,
                ]),
            ]);

            return [
                'type' => 'status_mismatch',
                'action' => 'updated_status',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
                'entity_id' => $payment->id,
                'from' => $oldStatus,
                'to' => 'succeeded',
            ];
        });
    }

    /**
     * Repair invoice_not_paid: mark Invoice as paid.
     * Idempotent: if already paid, skip.
     */
    private static function repairInvoiceNotPaid(array $drift, bool $dryRun, string $correlationId): array
    {
        $providerPaymentId = $drift['provider_payment_id'];
        $companyId = $drift['company_id'];
        $details = $drift['details'] ?? [];
        $invoiceId = $details['invoice_id'] ?? null;

        if (! $invoiceId) {
            return [
                'type' => 'invoice_not_paid',
                'action' => 'skipped_no_invoice_id',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        $invoice = Invoice::find((int) $invoiceId);

        if (! $invoice) {
            return [
                'type' => 'invoice_not_paid',
                'action' => 'skipped_invoice_not_found',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        // Idempotency: already paid
        if ($invoice->status === 'paid') {
            return [
                'type' => 'invoice_not_paid',
                'action' => 'skipped_idempotent',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
            ];
        }

        if ($dryRun) {
            return [
                'type' => 'invoice_not_paid',
                'action' => 'would_mark_paid',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
                'invoice_id' => $invoice->id,
                'from' => $invoice->status,
            ];
        }

        return DB::transaction(function () use ($invoice, $providerPaymentId, $companyId, $correlationId) {
            // Snapshot before mutation
            FinancialSnapshot::create([
                'company_id' => $companyId,
                'trigger' => 'auto_repair',
                'drift_type' => 'invoice_not_paid',
                'entity_type' => 'invoice',
                'entity_id' => (string) $invoice->id,
                'snapshot_data' => [
                    'status' => $invoice->status,
                    'amount_due' => $invoice->amount_due,
                    'paid_at' => $invoice->paid_at?->toISOString(),
                    'finalized_at' => $invoice->finalized_at?->toISOString(),
                    'metadata' => $invoice->metadata,
                ],
                'correlation_id' => $correlationId,
                'created_at' => now(),
            ]);

            $oldStatus = $invoice->status;
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'amount_due' => 0,
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'auto_repaired' => true,
                    'previous_status' => $oldStatus,
                    'correlation_id' => $correlationId,
                ]),
            ]);

            return [
                'type' => 'invoice_not_paid',
                'action' => 'marked_paid',
                'provider_payment_id' => $providerPaymentId,
                'company_id' => $companyId,
                'entity_id' => $invoice->id,
                'from' => $oldStatus,
                'to' => 'paid',
            ];
        });
    }
}
