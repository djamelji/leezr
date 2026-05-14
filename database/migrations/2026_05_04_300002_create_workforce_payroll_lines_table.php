<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('workforce_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees');
            $table->foreignId('timesheet_period_id')->constrained('workforce_timesheet_periods');
            $table->integer('worked_minutes')->default(0);
            $table->integer('break_minutes')->default(0);
            $table->integer('daily_overtime_minutes')->default(0);
            $table->integer('weekly_overtime_minutes')->default(0);
            $table->integer('total_overtime_minutes')->default(0);
            $table->integer('planned_minutes')->default(0);
            $table->integer('leave_days_hundredths')->default(0);
            $table->integer('paid_leave_days_hundredths')->default(0);
            $table->integer('unpaid_leave_days_hundredths')->default(0);
            $table->integer('leave_minutes')->default(0);
            $table->bigInteger('base_salary_cents')->default(0);
            $table->integer('overtime_rate_bps')->default(0);
            $table->bigInteger('gross_basis_cents')->default(0);
            $table->json('gross_breakdown');
            $table->json('compensation_snapshot');
            $table->json('timesheet_snapshot');
            $table->json('export_payload')->nullable();
            $table->json('anomalies')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_lines_unique');
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_lines');
    }
};
