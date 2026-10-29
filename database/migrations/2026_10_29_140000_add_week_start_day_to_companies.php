<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-645 (W-G4B) — préférence EXPLICITE de premier jour de semaine au niveau Company.
 *
 * Additive & non destructive : `week_start_day` NULL = pas de préférence → repli Market puis locale.
 * 0 = dimanche … 6 = samedi (convention ISO/JS getDay). N'altère AUCUN horaire déjà enregistré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('week_start_day')->nullable()->after('org_profile_key');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('week_start_day');
        });
    }
};
