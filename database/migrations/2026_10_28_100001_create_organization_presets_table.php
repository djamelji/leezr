<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-636 (W-E) — catalogue Platform des presets organisationnels, versionnés et publiables.
 * Clé de résolution : (jobdomain_key, market_key?, org_profile_key?). market/profile NULL = s'applique
 * à tout marché / tout profil (base jobdomain). La structure (départements + postes) est stockée en JSON.
 * Les organisations RÉSULTANTES vivent dans workforce_departments/workforce_job_roles (propres à chaque
 * Company) — ce catalogue est global et partagé, jamais une donnée Company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_presets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label');
            $table->string('jobdomain_key', 60);
            $table->string('market_key', 10)->nullable();
            $table->string('org_profile_key', 60)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->json('structure'); // {departments:[{key,name,parent_key,sort_order}], job_roles:[{key,title,department_key,level}]}
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['jobdomain_key', 'market_key', 'org_profile_key', 'status'], 'org_presets_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_presets');
    }
};
