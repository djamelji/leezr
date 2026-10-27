<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M0) — Billing Consolidé V2, migration STRICTEMENT ADDITIVE.
 *
 * Versionne le modèle de facturation par document : `1` = ADR-328 (facture principale + annexes,
 * documents historiques), `2` = consolidé (une facture / company / devise / cycle / période).
 *
 * M0 ne change AUCUN comportement : toute facture existante et toute facture créée par les chemins
 * actuels (InvoiceIssuer/BillingRenewCommand/AddonBillingListener) reste `1`. La colonne n'est lue par
 * aucun chemin tant que M2+ n'est pas livré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_model_version')->default(1)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('billing_model_version');
        });
    }
};
