<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-333: Fix tax_mode to 'exclusive' and recalculate invoices
 * affected by the inclusive tax bug (double-taxation).
 *
 * The bug: when tax_mode was 'inclusive', TaxResolver::compute() extracted
 * a smaller tax from the subtotal, but InvoiceIssuer::finalize() still did
 * total = subtotal + tax, effectively double-counting.
 *
 * Plan prices are always HT (B2B convention), so tax_mode must be 'exclusive'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Reset tax_mode to 'exclusive'
        DB::table('platform_billing_policies')
            ->where('tax_mode', 'inclusive')
            ->update(['tax_mode' => 'exclusive']);

        // 2. Recalculate affected invoices
        $invoices = DB::table('invoices')
            ->whereNotNull('finalized_at')
            ->where('tax_rate_bps', '>', 0)
            ->where('subtotal', '>', 0)
            ->get(['id', 'number', 'subtotal', 'tax_amount', 'tax_rate_bps', 'amount', 'wallet_credit_applied', 'amount_due', 'status', 'paid_at']);

        $fixed = 0;

        foreach ($invoices as $invoice) {
            $expectedTax = (int) floor($invoice->subtotal * $invoice->tax_rate_bps / 10000);

            if ($invoice->tax_amount === $expectedTax) {
                continue; // Already correct (exclusive mode was used)
            }

            $newAmount = $invoice->subtotal + $expectedTax;
            $newAmountDue = $newAmount - $invoice->wallet_credit_applied;

            $update = [
                'tax_amount' => $expectedTax,
                'amount' => $newAmount,
                'amount_due' => $newAmountDue,
            ];

            // If was marked paid but now has amount_due > 0, reopen
            if ($invoice->status === 'paid' && $newAmountDue > 0) {
                $update['status'] = 'open';
                $update['paid_at'] = null;
            }

            DB::table('invoices')
                ->where('id', $invoice->id)
                ->update($update);

            Log::info('[migration] Fixed invoice tax computation', [
                'invoice_number' => $invoice->number,
                'old_tax' => $invoice->tax_amount,
                'new_tax' => $expectedTax,
                'old_amount' => $invoice->amount,
                'new_amount' => $newAmount,
                'old_amount_due' => $invoice->amount_due,
                'new_amount_due' => $newAmountDue,
            ]);

            $fixed++;
        }

        Log::info("[migration] ADR-333: Fixed {$fixed} invoices with incorrect tax computation");
    }

    public function down(): void
    {
        // Corrective migration — not meaningfully reversible
    }
};
