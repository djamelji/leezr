<?php

namespace App\Core\Billing;

/**
 * ADR-141 D3e: Financial forensics — chronological timeline of all
 * financial events for a company.
 *
 * Aggregates invoices, payments, credit notes, wallet transactions,
 * and financial snapshots into a single sorted timeline.
 */
class FinancialForensicsService
{
    /**
     * Build a chronological financial timeline for a company.
     *
     * @param  int  $companyId
     * @param  int  $days  Look-back period in days (default 30)
     * @param  string|null  $entityType  Filter by entity type (invoice, payment, credit_note, wallet_transaction, snapshot)
     * @return array Sorted array of timeline entries
     */
    public static function timeline(int $companyId, int $days = 30, ?string $entityType = null): array
    {
        $since = now()->subDays($days);
        $entries = [];

        // Invoices
        if (! $entityType || $entityType === 'invoice') {
            $invoices = Invoice::where('company_id', $companyId)
                ->where('created_at', '>=', $since)
                ->get();

            foreach ($invoices as $invoice) {
                $entries[] = [
                    'timestamp' => $invoice->created_at->toISOString(),
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->id,
                    'action' => "invoice_{$invoice->status}",
                    'amount' => $invoice->amount_due,
                    'currency' => $invoice->currency,
                    'details' => [
                        'number' => $invoice->number,
                        'status' => $invoice->status,
                        'paid_at' => $invoice->paid_at?->toISOString(),
                        'finalized_at' => $invoice->finalized_at?->toISOString(),
                    ],
                ];
            }
        }

        // Payments
        if (! $entityType || $entityType === 'payment') {
            $payments = Payment::where('company_id', $companyId)
                ->where('created_at', '>=', $since)
                ->get();

            foreach ($payments as $payment) {
                $entries[] = [
                    'timestamp' => $payment->created_at->toISOString(),
                    'entity_type' => 'payment',
                    'entity_id' => $payment->id,
                    'action' => "payment_{$payment->status}",
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'details' => [
                        'provider' => $payment->provider,
                        'provider_payment_id' => $payment->provider_payment_id,
                        'status' => $payment->status,
                        'auto_repaired' => $payment->metadata['auto_repaired'] ?? false,
                    ],
                ];
            }
        }

        // Credit Notes
        if (! $entityType || $entityType === 'credit_note') {
            $creditNotes = CreditNote::where('company_id', $companyId)
                ->where('created_at', '>=', $since)
                ->get();

            foreach ($creditNotes as $cn) {
                $entries[] = [
                    'timestamp' => $cn->created_at->toISOString(),
                    'entity_type' => 'credit_note',
                    'entity_id' => $cn->id,
                    'action' => "credit_note_{$cn->status}",
                    'amount' => $cn->amount,
                    'currency' => $cn->currency,
                    'details' => [
                        'invoice_id' => $cn->invoice_id,
                        'reason' => $cn->reason,
                        'status' => $cn->status,
                    ],
                ];
            }
        }

        // Wallet Transactions
        if (! $entityType || $entityType === 'wallet_transaction') {
            $wallet = CompanyWallet::where('company_id', $companyId)->first();

            if ($wallet) {
                $transactions = CompanyWalletTransaction::where('wallet_id', $wallet->id)
                    ->where('created_at', '>=', $since)
                    ->get();

                foreach ($transactions as $tx) {
                    $entries[] = [
                        'timestamp' => $tx->created_at->toISOString(),
                        'entity_type' => 'wallet_transaction',
                        'entity_id' => $tx->id,
                        'action' => "wallet_{$tx->type}",
                        'amount' => $tx->amount,
                        'currency' => $wallet->currency,
                        'details' => [
                            'type' => $tx->type,
                            'balance_after' => $tx->balance_after,
                            'source_type' => $tx->source_type,
                            'description' => $tx->description,
                        ],
                    ];
                }
            }
        }

        // Financial Snapshots
        if (! $entityType || $entityType === 'snapshot') {
            $snapshots = FinancialSnapshot::where('company_id', $companyId)
                ->where('created_at', '>=', $since)
                ->get();

            foreach ($snapshots as $snap) {
                $entries[] = [
                    'timestamp' => $snap->created_at->toISOString(),
                    'entity_type' => 'snapshot',
                    'entity_id' => $snap->id,
                    'action' => "snapshot_{$snap->trigger}",
                    'amount' => null,
                    'currency' => null,
                    'details' => [
                        'trigger' => $snap->trigger,
                        'drift_type' => $snap->drift_type,
                        'target_entity_type' => $snap->entity_type,
                        'target_entity_id' => $snap->entity_id,
                        'correlation_id' => $snap->correlation_id,
                    ],
                ];
            }
        }

        // Sort chronologically
        usort($entries, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return $entries;
    }
}
