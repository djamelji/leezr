<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-793 (L3) — additive. Versioned presets for the RBAC, REQUIREMENTS and PLANNING families (the
 * ORGANIZATION family keeps its own table `organization_presets` and engine), the record of every
 * application to a company (all families), and the presets a JobDomain RECOMMENDS.
 * Nothing existing is altered ; down() drops only these three tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presets', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();          // identifiant de VERSION (ex. logistique_requirements_v2)
            $t->string('family_key', 80);               // identité stable du modèle
            $t->string('kind', 20);                     // rbac | requirements | planning
            $t->string('label');
            $t->text('description')->nullable();
            $t->string('jobdomain_key', 60)->nullable();
            $t->string('market_key', 10)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('draft'); // draft | published | archived
            $t->json('content');
            $t->json('requires_modules')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['family_key', 'version']);
            $t->index(['kind', 'status']);
        });

        Schema::create('preset_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('family_key', 80);
            $t->string('preset_key', 100);
            $t->unsignedInteger('preset_version');
            $t->json('selection')->nullable();
            $t->json('summary');
            $t->string('applied_by_type', 16)->nullable(); // platform | company
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'kind', 'family_key']);
        });

        Schema::create('jobdomain_preset_links', function (Blueprint $t) {
            $t->id();
            $t->string('jobdomain_key', 60);
            $t->string('market_key', 10)->nullable();
            $t->string('kind', 20);                     // rbac | organization | requirements | planning
            $t->string('family_key', 80);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['jobdomain_key', 'market_key', 'kind', 'family_key'], 'jd_preset_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobdomain_preset_links');
        Schema::dropIfExists('preset_applications');
        Schema::dropIfExists('presets');
    }
};
