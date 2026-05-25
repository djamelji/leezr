<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_compensation_plans', function (Blueprint $table) {
            $table->string('compensation_type', 20)->default('monthly')->after('contract_id');
            $table->unsignedInteger('hourly_rate_cents')->nullable()->after('base_salary_cents');
            $table->unsignedInteger('daily_rate_cents')->nullable()->after('hourly_rate_cents');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_compensation_plans', function (Blueprint $table) {
            $table->dropColumn(['compensation_type', 'hourly_rate_cents', 'daily_rate_cents']);
        });
    }
};
