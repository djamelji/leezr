<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->unique()->constrained('workforce_payroll_lines')->cascadeOnDelete();
            $table->string('rule_version', 50);
            $table->integer('plafond_ss_monthly_cents');
            $table->integer('gross_total_cents');
            $table->integer('contributions_employee_cents');
            $table->integer('contributions_employer_cents');
            $table->integer('total_cost_employer_cents');
            $table->integer('taxable_income_cents');
            $table->integer('tax_cents');
            $table->integer('net_before_tax_cents');
            $table->integer('net_payable_cents');
            $table->integer('benefits_cents')->default(0);
            $table->integer('deductions_cents')->default(0);
            $table->json('contribution_lines');
            $table->json('tax_breakdown');
            $table->json('benefit_lines')->nullable();
            $table->json('deduction_lines')->nullable();
            $table->json('blocking_anomalies')->nullable();
            $table->json('calculation_snapshot');
            $table->string('status', 20)->default('calculated');
            $table->timestamp('calculated_at');
            $table->unsignedBigInteger('calculated_by')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_calculations');
    }
};
