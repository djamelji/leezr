<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_dsn_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('workforce_payroll_runs')->cascadeOnDelete();
            $table->string('declaration_type', 20)->default('monthly');
            $table->string('period_month', 7); // YYYY-MM format
            $table->string('status', 20)->default('draft');
            $table->string('payload_hash', 64)->nullable(); // SHA-256
            $table->string('file_path')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('payload_snapshot')->nullable(); // DsnPayload::toArray() for audit
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('exported_by')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period_month']);
            $table->index(['payroll_run_id']);
            $table->index(['status']);
            $table->unique(['company_id', 'payroll_run_id'], 'dsn_company_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_dsn_declarations');
    }
};
