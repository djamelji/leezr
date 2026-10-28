<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-636 (W-E) — provenance des entités d'organisation : d'où vient un département/poste
 * (`source` = 'preset'|'company') et quelle entrée de preset l'a créé (`preset_item_key`, pour une
 * application IDEMPOTENTE et non destructive). ADDITIF ; les entités existantes restent `source=null`
 * (traitées comme personnalisations Company, jamais écrasées).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['workforce_departments', 'workforce_job_roles'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('source', 20)->nullable();
                $table->string('preset_item_key', 80)->nullable();
                $table->index(['company_id', 'preset_item_key']);
            });
        }
    }

    public function down(): void
    {
        foreach (['workforce_departments', 'workforce_job_roles'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['source', 'preset_item_key']);
            });
        }
    }
};
