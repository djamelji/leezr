<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_payroll_calculations', function (Blueprint $table) {
            $table->integer('net_social_cents')->nullable()->after('deductions_cents');
            $table->json('relief_lines')->nullable()->after('deduction_lines');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_payroll_calculations', function (Blueprint $table) {
            $table->dropColumn(['net_social_cents', 'relief_lines']);
        });
    }
};
