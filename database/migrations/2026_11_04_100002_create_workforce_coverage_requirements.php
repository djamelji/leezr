<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning SP1a (ADR-705) — besoins de PERSONNEL (coverage).
 *
 * « Combien de collaborateurs présents ? » — distinct de la demande d'activité (workload, SP2).
 * Récurrent (day_of_week 0=dim..6=sam) OU ponctuel (date). Fenêtre horaire optionnelle (null = journée).
 * Portée optionnelle Department / lieu / type d'activité. L'effectif requis est headcount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_coverage_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->date('date')->nullable();                 // ponctuel
            $table->unsignedTinyInteger('day_of_week')->nullable(); // récurrent : 0=dimanche .. 6=samedi
            $table->time('start_time')->nullable();           // null = toute la journée
            $table->time('end_time')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreignId('work_location_id')->nullable()->constrained('workforce_work_locations')->nullOnDelete();
            $table->string('activity_type', 60)->nullable();
            $table->unsignedInteger('headcount')->default(1);
            $table->string('priority', 20)->default('normal'); // low|normal|high|critical
            $table->string('source', 30)->default('manual');   // manual|template|jobdomain_preset
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'day_of_week']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_coverage_requirements');
    }
};
