<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-835 — per-JobDomain customisation of the company creation fields. Every column null = inherited from the
 * global catalog (field_definitions). Not a system catalog : SystemSync never reads nor writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobdomain_company_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobdomain_id')->constrained('jobdomains')->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('field_definitions')->cascadeOnDelete();
            $table->boolean('visible')->nullable();
            $table->boolean('required')->nullable();
            $table->json('markets')->nullable();
            $table->string('default_value', 500)->nullable();
            $table->unsignedBigInteger('updated_by_platform_user_id')->nullable();
            $table->timestamps();
            $table->unique(['jobdomain_id', 'field_definition_id'], 'jobdomain_company_fields_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobdomain_company_fields');
    }
};
