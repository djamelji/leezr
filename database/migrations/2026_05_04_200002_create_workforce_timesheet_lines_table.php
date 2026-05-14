<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_timesheet_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_period_id')->constrained('workforce_timesheet_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('daily_overtime_minutes')->default(0);
            $table->unsignedInteger('planned_minutes')->default(0);
            $table->unsignedInteger('leave_minutes')->default(0);
            $table->string('leave_type_code', 50)->nullable();
            $table->boolean('is_leave_day')->default(false);
            $table->boolean('is_rest_day')->default(false);
            $table->json('anomalies')->nullable();
            $table->json('source_snapshot');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['timesheet_period_id', 'date'], 'timesheet_line_unique');
            $table->index(['company_id', 'employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_timesheet_lines');
    }
};
