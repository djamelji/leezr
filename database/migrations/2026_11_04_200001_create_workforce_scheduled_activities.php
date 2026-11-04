<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning SP1b (ADR-705 v2, ADR-706) — ScheduledActivity : plusieurs activités/missions/
 * prestations DANS un seul Shift, sans créer de nouveau Shift par tâche.
 *
 * Cycle de vie analysé : un Shift n'est supprimable qu'à l'état brouillon (ShiftController@destroy) ;
 * ses activités planifiées sont des child-rows possédées → cascadeOnDelete est correct et non surprenant
 * (même précédent que TimeEntryBreak sur TimeEntry). Un Shift publié n'est jamais supprimé (seulement annulé).
 *
 * duration_minutes n'est PAS stockée (dérivable de start_at/end_at) — pas de dénormalisation inutile.
 * employee_id EST dénormalisé mais STRICTEMENT dérivé du Shift côté serveur (jamais saisi) → cohérence garantie,
 * requêtes « activités d'un salarié sur une journée » + scope sans jointure.
 * subject_type/subject_id (nullableMorphs) = référence métier externe OPTIONNELLE, dormante tant qu'aucun
 * provider n'existe (pas d'infrastructure générique disproportionnée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_scheduled_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('workforce_shifts')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id'); // dérivé du shift (cohérence serveur)
            $table->foreignId('schedule_template_id')->nullable()->constrained('workforce_schedule_templates')->nullOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('workforce_work_locations')->nullOnDelete();
            $table->string('activity_type', 60)->nullable();
            $table->string('label')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 20)->default('planned'); // planned|cancelled
            $table->string('priority', 20)->default('normal');
            $table->string('source', 30)->default('manual');
            $table->text('notes')->nullable();
            $table->nullableMorphs('subject'); // référence métier externe optionnelle (dormante)
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'shift_id']);
            $table->index(['company_id', 'employee_id']);
            $table->index(['shift_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_scheduled_activities');
    }
};
