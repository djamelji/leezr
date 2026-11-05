<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot 3 complément (ADR-717) — absences PARTIELLES.
 *
 * Additif et rétro-compatible : les colonnes sont nullables. Une demande héritée (start_time/end_time NULL)
 * reste une absence JOUR ENTIER (comportement inchangé). Le décompte en jours (days_count_hundredths) n'est
 * PAS modifié ; hours_count_hundredths est purement informatif (dimension planning/couverture), il n'altère
 * aucun solde historique ni aucun calcul Payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_leave_requests', function (Blueprint $table) {
            // Heure de début sur date_from / de fin sur date_to (heure murale locale). NULL = bornes de journée.
            $table->time('start_time')->nullable()->after('date_to');
            $table->time('end_time')->nullable()->after('start_time');
            // Heures réelles d'absence ×100 (ex. 400 = 4,00 h) pour les absences horaires — informatif.
            $table->integer('hours_count_hundredths')->nullable()->after('days_count_hundredths');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_leave_requests', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'hours_count_hundredths']);
        });
    }
};
