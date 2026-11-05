<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot A (ADR-710) — CONTRIBUTION à la couverture.
 *
 * Un modèle de shift et une activité peuvent préciser la catégorie de couverture à laquelle ils
 * contribuent ; un besoin cible une catégorie. Pas de FK en ALTER (SQLite) : référence souple validée serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_schedule_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('coverage_category_id')->nullable()->after('activity_type');
        });
        Schema::table('workforce_coverage_requirements', function (Blueprint $table) {
            $table->unsignedBigInteger('coverage_category_id')->nullable()->after('activity_type');
        });
        Schema::table('workforce_scheduled_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('coverage_category_id')->nullable()->after('activity_type');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_schedule_templates', fn (Blueprint $t) => $t->dropColumn('coverage_category_id'));
        Schema::table('workforce_coverage_requirements', fn (Blueprint $t) => $t->dropColumn('coverage_category_id'));
        Schema::table('workforce_scheduled_activities', fn (Blueprint $t) => $t->dropColumn('coverage_category_id'));
    }
};
