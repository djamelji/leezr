<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payroll_rule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 50);
            $table->string('rule_type', 30);
            $table->string('rule_key', 100);
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('override_mode', 20)->default('replace'); // replace, supplement, minimum
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('justification', 500)->nullable();
            $table->string('source', 100);
            $table->string('reference', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'domain', 'rule_type', 'rule_key', 'effective_from'],
                'cpro_unique'
            );
            $table->index(['company_id', 'domain', 'rule_type'], 'cpro_domain_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payroll_rule_overrides');
    }
};
