<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('workforce_leave_types')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->integer('days_count_hundredths'); // 500 = 5.00 days
            $table->string('status', 20)->default('draft'); // draft, pending, approved, consumed, rejected, cancelled
            $table->text('reason')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->uuid('ledger_correlation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'date_from']);
            $table->index(['company_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_leave_requests');
    }
};
