<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-310: Tax exemption reason on invoices for EU compliance.
 *
 * Values: reverse_charge_intra_eu | export_extra_eu | null (standard VAT)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_exemption_reason', 50)->nullable()->after('tax_rate_bps');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_exemption_reason');
        });
    }
};
