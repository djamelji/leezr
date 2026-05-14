<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive, on_leave, suspended, terminated
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_number'], 'we_employee_number_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_employees');
    }
};
