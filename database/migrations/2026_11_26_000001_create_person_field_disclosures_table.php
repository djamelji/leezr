<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-795.1 — à quelle société une donnée PERSONNELLE stockée sur le compte a été communiquée. Le
// stockage technique (compte) ne vaut pas autorisation : un employeur ne lit que ce qui lui a été
// communiqué (par la personne ou par sa propre collecte). Additif ; aucune donnée recopiée.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('person_field_disclosures')) {
            return;
        }
        Schema::create('person_field_disclosures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('field_definitions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // self | members | hr | suggestion
            $table->timestamps();

            $table->unique(['user_id', 'field_definition_id', 'company_id'], 'person_field_disclosure_unique');
            $table->index(['company_id'], 'person_field_disclosure_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_field_disclosures');
    }
};
