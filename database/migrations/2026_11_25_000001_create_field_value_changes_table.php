<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-795 (L5) — historique des modifications de valeurs dynamiques portées par une fiche salarié (ou
// reprises d'une valeur historique). Additif. Une valeur sensible n'est jamais recopiée en clair : seule
// son empreinte est conservée.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_value_changes')) {
            return;
        }
        Schema::create('field_value_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('field_definitions')->cascadeOnDelete();
            $table->string('model_type', 40);
            $table->unsignedBigInteger('model_id');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->boolean('masked')->default(false); // sensible : empreintes seulement
            $table->string('source', 30);             // hr_edit | legacy_adoption
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'model_type', 'model_id'], 'field_val_chg_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_value_changes');
    }
};
