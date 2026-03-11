<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-328 LOT H: Addon invoices as annexes of the main subscription invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('parent_invoice_id')
                ->nullable()
                ->after('subscription_id')
                ->constrained('invoices')
                ->nullOnDelete();

            $table->string('annexe_suffix', 5)
                ->nullable()
                ->after('number');

            $table->index('parent_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['parent_invoice_id']);
            $table->dropColumn(['parent_invoice_id', 'annexe_suffix']);
        });
    }
};
