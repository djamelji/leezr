<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('workforce_leave_types')->cascadeOnDelete();
            $table->smallInteger('period_year'); // 2026
            $table->integer('cached_accrued')->default(0); // hundredths
            $table->integer('cached_reserved')->default(0); // hundredths
            $table->integer('cached_consumed')->default(0); // hundredths
            $table->integer('cached_adjusted')->default(0); // hundredths (adjustments + stornos)
            $table->integer('cached_available')->default(0); // = accrued - reserved - consumed + adjusted
            $table->dateTime('last_computed_at');
            $table->timestamps();

            $table->unique(
                ['company_id', 'employee_id', 'leave_type_id', 'period_year'],
                'wlb_company_employee_type_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_leave_balances');
    }
};
