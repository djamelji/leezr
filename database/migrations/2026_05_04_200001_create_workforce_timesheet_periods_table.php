<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_timesheet_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'locked'])->default('draft');
            $table->unsignedInteger('total_worked_minutes')->default(0);
            $table->unsignedInteger('total_break_minutes')->default(0);
            $table->unsignedInteger('total_overtime_minutes')->default(0);
            $table->unsignedInteger('total_planned_minutes')->default(0);
            $table->unsignedInteger('total_leave_days_hundredths')->default(0);
            $table->unsignedInteger('anomaly_count')->default(0);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('policy_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'period_start', 'period_end'], 'timesheet_period_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period_start', 'period_end'], 'timesheet_period_range');
            $table->index(['company_id', 'employee_id', 'period_start', 'period_end'], 'timesheet_period_overlap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_timesheet_periods');
    }
};
