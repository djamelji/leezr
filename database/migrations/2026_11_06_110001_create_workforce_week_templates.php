<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte Smart Planning — M3 (ADR-726) : SEMAINES TYPES réutilisables (WeekTemplate + lignes).
 *
 * Référence réutilisable (PAS un planning publié). Une ligne définit, pour un jour RELATIF au premier jour
 * de semaine de la Company (day_index 0..6, jamais lundi en dur), un ScheduleTemplate (référence VIVANTE,
 * résolue et figée par valeur à l'application comme en M1), et une stratégie : NOMINATIVE (employee_id) ou
 * PAR BESOINS (headcount à pourvoir). Plusieurs lignes le même jour autorisées.
 *
 * Références souples (schedule_template_id / employee_id / work_location_id / department_id / coverage_category_id)
 * validées serveur — AUCUNE FK sortante : supprimer un modèle/salarié ne casse pas la semaine type, la ligne
 * est signalée « invalide » à la prévisualisation. Une semaine type n'est JAMAIS une 2e source de vérité des
 * CoverageRequirement (les besoins qu'elle exprime ≠ CoverageRequirement opérationnels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_week_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('strategy', 20)->default('nominative'); // nominative | needs | mixed
            $table->string('scope', 20)->default('company');       // company | department | location
            $table->unsignedBigInteger('department_id')->nullable(); // réf souple
            $table->unsignedBigInteger('work_location_id')->nullable(); // réf souple
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'enabled']);
        });

        Schema::create('workforce_week_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_template_id')->constrained('workforce_week_templates')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_index');               // 0..6 RELATIF au premier jour Company
            $table->unsignedBigInteger('schedule_template_id');     // réf souple (modèle de shift)
            $table->string('kind', 20)->default('nominative');      // nominative | needs
            $table->unsignedBigInteger('employee_id')->nullable();  // nominative : salarié désigné (réf souple)
            $table->unsignedInteger('headcount')->nullable();       // needs : effectif à pourvoir
            $table->unsignedBigInteger('work_location_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('coverage_category_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['week_template_id', 'day_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_week_template_lines');
        Schema::dropIfExists('workforce_week_templates');
    }
};
