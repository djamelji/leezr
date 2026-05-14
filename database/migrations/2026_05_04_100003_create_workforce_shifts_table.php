<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('schedule_template_id')->nullable()->constrained('workforce_schedule_templates')->nullOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('workforce_work_locations')->nullOnDelete();
            $table->json('template_snapshot')->nullable();
            $table->date('date');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('timezone', 64);
            $table->enum('status', ['draft', 'published', 'completed', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'date', 'status']);
            $table->index(['employee_id', 'date']);
            $table->index(['company_id', 'employee_id', 'start_at', 'end_at'], 'shifts_overlap_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_shifts');
    }
};
