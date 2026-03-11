<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-328 LOT I S2: Preferred SEPA debit day per payment profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_payment_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('preferred_debit_day')
                ->nullable()
                ->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('company_payment_profiles', function (Blueprint $table) {
            $table->dropColumn('preferred_debit_day');
        });
    }
};
