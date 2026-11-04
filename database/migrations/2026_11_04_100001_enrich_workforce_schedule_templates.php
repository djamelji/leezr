<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning SP1a (ADR-705) — enrichit les modèles de shifts sans créer de catalogue parallèle.
 * Ajoute code, icône, type d'activité et portée Department (nullable = Company-wide).
 * Pas de FK ajoutée en ALTER (incompatible SQLite) : department_id est une référence souple validée côté serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_schedule_templates', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->after('name');
            $table->string('icon', 64)->nullable()->after('color');
            $table->string('activity_type', 60)->nullable()->after('icon');
            $table->unsignedBigInteger('department_id')->nullable()->after('work_location_id');
            $table->index(['company_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workforce_schedule_templates', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'department_id']);
            $table->dropColumn(['code', 'icon', 'activity_type', 'department_id']);
        });
    }
};
