<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-820 — a module DATA NEED (declared in its manifest) bound to a field the company already owns, by the
 * field's STABLE id (a renamed label never breaks the binding). The module reads the existing values through
 * the canonical field store : nothing is copied. A binding exists only once the company confirmed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_module_field_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('module_key', 100);
            $t->string('need_key', 60);
            $t->foreignId('field_definition_id')->constrained('field_definitions')->restrictOnDelete();
            $t->timestamp('confirmed_at');
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'module_key', 'need_key'], 'cmfm_need_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_field_mappings');
    }
};
