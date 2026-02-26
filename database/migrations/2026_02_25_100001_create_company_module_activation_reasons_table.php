<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_module_activation_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('reason'); // direct, plan, bundle, required
            $table->string('source_module_key')->nullable(); // which module required this one
            $table->timestamps();

            $table->unique(
                ['company_id', 'module_key', 'reason', 'source_module_key'],
                'cmar_unique',
            );

            $table->index(['company_id', 'module_key'], 'cmar_company_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_activation_reasons');
    }
};
