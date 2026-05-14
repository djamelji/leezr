<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convention_collective_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convention_collective_id')->constrained('convention_collectives')->cascadeOnDelete();
            $table->string('domain', 50);           // workforce_payroll, workforce_leave, etc.
            $table->string('rule_type', 30);         // contribution, salary_minimum, overtime, etc.
            $table->string('rule_key', 100);
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('override_mode', 20)->default('replace'); // replace, supplement, minimum
            $table->string('source', 100);
            $table->string('reference', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['convention_collective_id', 'domain', 'rule_type', 'rule_key', 'effective_from'],
                'ccr_unique'
            );
            $table->index(['convention_collective_id', 'domain', 'rule_type'], 'ccr_domain_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convention_collective_rules');
    }
};
