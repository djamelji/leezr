<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-760 (DMS M3) — moteur d'échéances / renouvellement / relances :
 *  - `document_requests.last_reminded_at` : dédup DÉTERMINISTE des relances (plus de mutation de
 *    `requested_at`) ;
 *  - `company_document_settings.reminder_policy` (JSON) : tiers de relance CONFIGURABLES par société
 *    (avant/à/après expiration, avant/après échéance de demande) — aucune valeur codée en dur dans
 *    la logique (défauts dans le modèle).
 *
 * Additif et réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('reviewed_at');
        });

        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->json('reminder_policy')->nullable()->after('remind_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->dropColumn('reminder_policy');
        });
    }
};
