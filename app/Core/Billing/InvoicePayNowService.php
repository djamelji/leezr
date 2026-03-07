<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-135 D1: Pay open/overdue invoices using wallet credit.
 *
 * Wallet: policy.auto_apply_wallet_credit controls whether wallet is used.
 * Idempotency: deterministic keys per invoice ("pay-now-{key}-inv-{id}").
 * Replay-safe: double call with same key → no double debit.
 */
class InvoicePayNowService
{
    /**
     * Pay all open/overdue invoices for a company using wallet credit.
     *
     * @return array{invoices_paid: int, total_amount: int, wallet_used: int, paid_invoice_ids: int[]}
     *
     * @throws RuntimeException If no open invoices
     */
    public static function payNow(
        Company $company,
        string $idempotencyKey,
        ?int $userId = null,
    ): array {
        $policy = PlatformBillingPolicy::instance();

        return DB::transaction(function () use ($company, $policy, $idempotencyKey, $userId) {
            $invoices = Invoice::where('company_id', $company->id)
                ->whereIn('status', ['open', 'overdue'])
                ->whereNotNull('finalized_at')
                ->orderBy('due_at')
                ->lockForUpdate()
                ->get();

            if ($invoices->isEmpty()) {
                throw new RuntimeException('No open invoices.');
            }

            $totalAmount = 0;
            $walletUsed = 0;
            $invoicesPaid = 0;
            $paidInvoiceIds = [];

            foreach ($invoices as $invoice) {
                $remaining = $invoice->amount_due;

                if ($remaining <= 0) {
                    // Fully covered by prior wallet credit — just mark paid
                    if (in_array($invoice->status, ['open', 'overdue'])) {
                        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                        $invoicesPaid++;
                        $paidInvoiceIds[] = $invoice->id;
                        $totalAmount += $invoice->amount;
                    }

                    continue;
                }

                // Wallet-first payment
                if ($policy->auto_apply_wallet_credit) {
                    $walletBalance = WalletLedger::balance($company);

                    if ($walletBalance > 0) {
                        $walletPayment = min($walletBalance, $remaining);

                        WalletLedger::debit(
                            company: $company,
                            amount: $walletPayment,
                            sourceType: 'invoice_payment',
                            sourceId: $invoice->id,
                            description: "Pay now: invoice {$invoice->number}",
                            actorType: 'user',
                            actorId: $userId,
                            idempotencyKey: "pay-now-{$idempotencyKey}-inv-{$invoice->id}",
                        );

                        $walletUsed += $walletPayment;
                        $remaining -= $walletPayment;

                        $invoice->update([
                            'wallet_credit_applied' => $invoice->wallet_credit_applied + $walletPayment,
                            'amount_due' => $remaining,
                        ]);
                    }
                }

                if ($remaining <= 0) {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                    $invoicesPaid++;
                    $paidInvoiceIds[] = $invoice->id;
                    $totalAmount += $invoice->amount;
                }
            }

            return [
                'invoices_paid' => $invoicesPaid,
                'total_amount' => $totalAmount,
                'wallet_used' => $walletUsed,
                'paid_invoice_ids' => $paidInvoiceIds,
            ];
        });
    }
}
