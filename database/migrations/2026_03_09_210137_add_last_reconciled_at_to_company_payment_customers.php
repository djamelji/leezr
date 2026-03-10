<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_payment_customers', function (Blueprint $table) {
            $table->timestamp('last_reconciled_at')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('company_payment_customers', function (Blueprint $table) {
            $table->dropColumn('last_reconciled_at');
        });
    }
};
