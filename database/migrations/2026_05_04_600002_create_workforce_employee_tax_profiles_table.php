<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_employee_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->integer('tax_rate_bps');
            $table->string('tax_rate_source', 20)->default('default');
            $table->string('tax_domicile', 10)->default('FR');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'effective_from'], 'tax_profile_unique');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_employee_tax_profiles');
    }
};
