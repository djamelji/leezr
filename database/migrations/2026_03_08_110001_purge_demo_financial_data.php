<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-276: Purge DEMO financial data.
 *
 * DEMO invoices, payments, credit notes and their ledger entries were seeded
 * with inconsistent amounts that pollute AR calculations and billing widgets.
 * Real invoices (INV-*, CN-*) are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Identify DEMO invoice IDs
        $demoInvoiceIds = DB::table('invoices')
            ->where('number', 'LIKE', 'DEMO%')
            ->pluck('id')
            ->toArray();

        // 2. Identify DEMO payment IDs (linked to demo invoices)
        $demoPaymentIds = DB::table('payments')
            ->whereIn('invoice_id', $demoInvoiceIds)
            ->pluck('id')
            ->toArray();

        // 3. Identify DEMO credit note IDs
        $demoCreditNoteIds = DB::table('credit_notes')
            ->where('number', 'LIKE', 'DEMO%')
            ->pluck('id')
            ->toArray();

        // 4. Delete ledger entries referencing DEMO records
        DB::table('financial_ledger_entries')
            ->where(function ($q) use ($demoInvoiceIds, $demoPaymentIds, $demoCreditNoteIds) {
                $q->where(function ($q2) use ($demoInvoiceIds) {
                    $q2->where('reference_type', 'invoice')
                       ->whereIn('reference_id', $demoInvoiceIds);
                })
                ->orWhere(function ($q2) use ($demoPaymentIds) {
                    $q2->where('reference_type', 'payment')
                       ->whereIn('reference_id', $demoPaymentIds);
                })
                ->orWhere(function ($q2) use ($demoCreditNoteIds) {
                    $q2->where('reference_type', 'credit_note')
                       ->whereIn('reference_id', $demoCreditNoteIds);
                });
            })
            ->delete();

        // 5. Delete DEMO credit notes
        DB::table('credit_notes')
            ->whereIn('id', $demoCreditNoteIds)
            ->delete();

        // 6. Delete DEMO payments
        DB::table('payments')
            ->whereIn('id', $demoPaymentIds)
            ->delete();

        // 7. Delete DEMO invoices
        DB::table('invoices')
            ->whereIn('id', $demoInvoiceIds)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible — re-run FinanceDemoSeeder if needed
    }
};
