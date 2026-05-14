<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('workforce_leave_types')->cascadeOnDelete();
            $table->string('entry_type', 20); // accrual, consumption, reservation, release, adjustment, storno, carry_over, grant
            $table->integer('credit')->default(0); // hundredths of days
            $table->integer('debit')->default(0); // hundredths of days
            $table->integer('balance_after'); // hundredths (snapshot at write time)
            $table->string('reference_type', 50)->nullable(); // leave_request, accrual_run, manual_adjustment
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('period_key', 10); // '2026-01', '2026'
            $table->string('idempotency_key', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('actor_type', 20)->default('system'); // user, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTime('recorded_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            // NO updated_at — this table is IMMUTABLE

            $table->index(['company_id', 'employee_id', 'leave_type_id'], 'wlle_company_employee_type');
            $table->index(['employee_id', 'leave_type_id', 'period_key'], 'wlle_employee_type_period');
            $table->index('correlation_id');
            $table->unique('idempotency_key', 'wlle_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_leave_ledger_entries');
    }
};
