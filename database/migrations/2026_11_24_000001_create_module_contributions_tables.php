<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-794 (L4) — contributions des modules : provenance des définitions/propositions déclarées par les
// manifestes, marquage explicite des MODÈLES de proposition dans le registre (jamais appliqués seuls),
// et journal des décisions Company. Additif uniquement.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_contributions')) {
            Schema::create('module_contributions', function (Blueprint $table) {
                $table->id();
                $table->string('module_key', 80);
                $table->string('kind', 20);                 // field | document | requirement
                $table->string('ref', 191);                 // identifiant stable dans le module
                $table->unsignedInteger('version');         // version du module où le contenu a changé
                $table->string('fingerprint', 64);
                $table->json('payload');
                $table->foreignId('field_definition_id')->nullable()->constrained('field_definitions')->nullOnDelete();
                $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
                $table->string('status', 20)->default('active'); // active | withdrawn | collision
                $table->string('detail', 191)->nullable();
                $table->timestamps();

                $table->unique(['module_key', 'kind', 'ref'], 'module_contrib_unique');
                $table->index(['kind', 'status'], 'module_contrib_kind_status_idx');
            });
        }

        if (! Schema::hasColumn('requirements', 'is_template')) {
            Schema::table('requirements', function (Blueprint $table) {
                // Une proposition (modèle) n'est JAMAIS une exigence : elle ne compte qu'une fois adoptée.
                $table->boolean('is_template')->default(false)->after('derived_from_id');
            });
        }

        if (! Schema::hasTable('module_contribution_decisions')) {
            Schema::create('module_contribution_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('module_key', 80);
                $table->string('ref', 191);
                $table->unsignedInteger('version');
                $table->string('decision', 30);              // adopted | updated | declined | kept_customization | replaced_customization
                $table->unsignedBigInteger('requirement_id')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'module_key'], 'module_contrib_dec_company_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_contribution_decisions');
        if (Schema::hasColumn('requirements', 'is_template')) {
            Schema::table('requirements', fn (Blueprint $t) => $t->dropColumn('is_template'));
        }
        Schema::dropIfExists('module_contributions');
    }
};
