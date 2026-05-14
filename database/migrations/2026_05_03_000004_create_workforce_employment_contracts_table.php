<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->string('contract_type', 30); // cdi, cdd, stage, alternance, freelance
            $table->string('work_model_key', 30); // horaire, forfait_jours, forfait_heures
            $table->decimal('weekly_hours', 5, 2)->default(35.00);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft, active, suspended, terminated
            $table->boolean('is_current')->default(false);
            $table->json('custom_compliance_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // is_current uniqueness enforced at application level (UseCase guard)
            // A partial unique index (WHERE is_current = true) is not supported in Laravel Blueprint
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_employment_contracts');
    }
};
