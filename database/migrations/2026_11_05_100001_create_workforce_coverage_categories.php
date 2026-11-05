<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot A (ADR-710) — catalogue de CATÉGORIES DE COUVERTURE par Company.
 *
 * Distingue la PRÉSENCE RH de la COUVERTURE OPÉRATIONNELLE : une catégorie (Route, Formation, Cuisine…)
 * qualifie à quel besoin un shift/une activité contribue réellement. Jamais codé en dur par JobDomain
 * (le JobDomain propose des presets, la Company adapte). Portée Company + Department optionnelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_coverage_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('resource_type', 40)->nullable(); // type de ressource attendu (ex. production, support)
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->json('conditions')->nullable(); // conditions éventuelles (compétence/rôle)
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_coverage_categories');
    }
};
