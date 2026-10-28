<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-638 (W-E2) — versionnement par FAMILLE de presets : `family_key` regroupe les versions successives
 * d'un même modèle logique (identifiant stable) ; `key` reste l'identifiant unique de version. ADDITIF.
 * `updated_by` = auteur de la dernière modification. Les presets existants reçoivent family_key = key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_presets', function (Blueprint $table) {
            $table->string('family_key', 80)->nullable()->after('key');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            $table->index(['family_key', 'version']);
        });

        // Backfill : family_key = key pour les presets existants.
        DB::table('organization_presets')->whereNull('family_key')->update(['family_key' => DB::raw('`key`')]);
    }

    public function down(): void
    {
        Schema::table('organization_presets', function (Blueprint $table) {
            $table->dropColumn(['family_key', 'updated_by']);
        });
    }
};
