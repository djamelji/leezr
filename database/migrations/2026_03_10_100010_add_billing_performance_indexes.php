<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-312: Performance indexes for billing batch scans.
 *
 * - idx_sub_renewal_scan: BillingRenewCommand daily scan
 * - idx_inv_dunning_overdue: DunningEngine overdue scan
 * - idx_inv_company_list: Company invoice listings
 * - idx_ledger_aggregates: Dashboard widget aggregates
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(
                ['current_period_end', 'status', 'is_current'],
                'idx_sub_renewal_scan'
            );
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(
                ['status', 'due_at', 'finalized_at'],
                'idx_inv_dunning_overdue'
            );
            $table->index(
                ['company_id', 'status', 'created_at'],
                'idx_inv_company_list'
            );
        });

        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->index(
                ['company_id', 'account_code', 'recorded_at'],
                'idx_ledger_aggregates'
            );
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_sub_renewal_scan');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_inv_dunning_overdue');
            $table->dropIndex('idx_inv_company_list');
        });

        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('idx_ledger_aggregates');
        });
    }
};
