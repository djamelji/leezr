<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-274: Uniformize ledger entries to store amounts in cents (smallest currency unit),
 * matching invoices, payments, and wallet transactions.
 *
 * Previously the ledger stored in decimal euros (amount / 100).
 * This migration multiplies all debit/credit values by 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('financial_ledger_entries')
            ->where(function ($q) {
                $q->where('debit', '>', 0)
                  ->orWhere('credit', '>', 0);
            })
            ->update([
                'debit' => DB::raw('debit * 100'),
                'credit' => DB::raw('credit * 100'),
            ]);
    }

    public function down(): void
    {
        DB::table('financial_ledger_entries')
            ->where(function ($q) {
                $q->where('debit', '>', 0)
                  ->orWhere('credit', '>', 0);
            })
            ->update([
                'debit' => DB::raw('debit / 100'),
                'credit' => DB::raw('credit / 100'),
            ]);
    }
};
