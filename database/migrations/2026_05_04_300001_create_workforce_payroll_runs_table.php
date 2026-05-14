<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('pay_frequency_scope', 20)->nullable()->comment('monthly|biweekly|weekly|mixed');
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3);
            $table->bigInteger('total_gross_cents')->default(0);
            $table->integer('total_worked_minutes')->default(0);
            $table->integer('total_overtime_minutes')->default(0);
            $table->integer('total_leave_days_hundredths')->default(0);
            $table->integer('employee_count')->default(0);
            $table->json('anomalies')->nullable();
            $table->integer('anomaly_count')->default(0);
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('computed_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->text('validation_note')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('exported_at')->nullable();
            $table->string('export_format', 50)->nullable();
            $table->string('export_path')->nullable();
            $table->json('exported_snapshot')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'payroll_runs_idempotency');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period_start', 'period_end'], 'payroll_runs_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_runs');
    }
};
