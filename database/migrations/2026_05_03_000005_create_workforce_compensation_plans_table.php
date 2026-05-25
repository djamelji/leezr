<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_compensation_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('workforce_employment_contracts')->cascadeOnDelete();
            $table->integer('base_salary_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('pay_frequency', 20)->default('monthly'); // monthly, biweekly, weekly
            $table->integer('overtime_rate_bps')->default(2500); // basis points (25.00% = 2500)
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'effective_from']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_compensation_plans');
    }
};
