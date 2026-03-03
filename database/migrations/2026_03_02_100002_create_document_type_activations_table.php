<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('required_override')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'document_type_id'],
                'doc_act_company_type_unique',
            );
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_activations');
    }
};
