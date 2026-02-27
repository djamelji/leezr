<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // ── Numbering ──
            $table->string('number', 30)->nullable()->unique()->after('id');

            // ── Line-item totals (amount stays as total = subtotal + tax_amount) ──
            $table->bigInteger('subtotal')->default(0)->after('amount');
            $table->bigInteger('tax_amount')->default(0)->after('subtotal');
            $table->smallInteger('tax_rate_bps')->default(0)->after('tax_amount');

            // ── Wallet integration ──
            $table->bigInteger('wallet_credit_applied')->default(0)->after('tax_rate_bps');
            $table->bigInteger('amount_due')->default(0)->after('wallet_credit_applied');

            // ── Billing period ──
            $table->date('period_start')->nullable()->after('amount_due');
            $table->date('period_end')->nullable()->after('period_start');

            // ── Billing snapshot (frozen company info at invoice time) ──
            $table->json('billing_snapshot')->nullable()->after('period_end');

            // ── Dunning ──
            $table->smallInteger('retry_count')->default(0)->after('paid_at');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');

            // ── Lifecycle ──
            $table->timestamp('finalized_at')->nullable()->after('next_retry_at');
            $table->timestamp('voided_at')->nullable()->after('finalized_at');
            $table->text('notes')->nullable()->after('voided_at');

            // ── Indexes ──
            $table->index(['company_id', 'period_start']);
            $table->index(['status', 'next_retry_at']);
        });

        // Upcast existing `amount` column from integer to bigInteger
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('amount')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'period_start']);
            $table->dropIndex(['status', 'next_retry_at']);

            $table->dropColumn([
                'number', 'subtotal', 'tax_amount', 'tax_rate_bps',
                'wallet_credit_applied', 'amount_due',
                'period_start', 'period_end', 'billing_snapshot',
                'retry_count', 'next_retry_at',
                'finalized_at', 'voided_at', 'notes',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
