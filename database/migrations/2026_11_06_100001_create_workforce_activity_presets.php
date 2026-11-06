<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte Smart Planning — M2 (ADR-725) : catalogue de TÂCHES TYPES réutilisables (ActivityPreset) par Company.
 *
 * Référence réutilisable et modifiable, DISTINCTE :
 *  - de la catégorie de couverture (CoverageCategory : à quel besoin on contribue) ;
 *  - de l'activité d'une composition de modèle (copie par valeur dans ScheduleTemplate.metadata) ;
 *  - de la ScheduledActivity (instance réelle planifiée dans un Shift).
 *
 * Les valeurs d'un preset sont COPIÉES PAR VALEUR à l'insertion dans une composition (autonomes) : modifier
 * ou désactiver un preset n'altère JAMAIS une composition ni une ScheduledActivity déjà créée. Pas de FK
 * sortante (référence souple validée serveur), donc aucune cascade destructive sur les plannings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_activity_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->string('activity_type', 60)->nullable();
            $table->unsignedBigInteger('coverage_category_id')->nullable(); // réf souple validée serveur
            $table->unsignedBigInteger('work_location_id')->nullable();     // réf souple validée serveur
            $table->unsignedInteger('default_duration_minutes')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->boolean('non_exclusive')->default(false);
            $table->string('color', 7)->nullable();
            $table->string('icon', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_activity_presets');
    }
};
