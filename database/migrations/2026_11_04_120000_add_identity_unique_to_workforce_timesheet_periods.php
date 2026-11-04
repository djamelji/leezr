<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3-checkpoint (ADR-699) — garde-fou de CONCURRENCE au niveau DB.
 *
 * Identité métier d'une feuille de temps = (company_id, employee_id, period_start, period_end).
 * Deux générations SIMULTANÉES d'une période INÉDITE (mêmes bornes) pouvaient créer un doublon faute de
 * contrainte serveur (le garde applicatif + le verrou de ligne ne couvrent pas l'insertion fantôme). On
 * impose donc une contrainte d'UNICITÉ sur cette identité. Le contrôle des périodes CHEVAUCHANTES (bornes
 * DIFFÉRENTES) reste applicatif (GenerateTimesheetUseCase::overlapping) — il ne peut pas s'exprimer en index.
 *
 * ADDITIF & NON DESTRUCTIF : aucune donnée supprimée ; vérifié au préalable → 0 doublon existant (DEV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_timesheet_periods', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'employee_id', 'period_start', 'period_end'],
                'wf_ts_period_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workforce_timesheet_periods', function (Blueprint $table) {
            $table->dropUnique('wf_ts_period_identity_unique');
        });
    }
};
