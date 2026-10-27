<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M7) — VENTILATION ANALYTIQUE par ligne de facture. ADDITIVE.
 *
 * Ce n'est PAS un second grand livre : aucun débit/crédit/compte, aucune créance. C'est la
 * décomposition (plan/module/addon/prorata/remise) du REVENUE + TVA d'une facture, référençant
 * `invoice_id` + `invoice_line_id`, réconciliable AU CENTIME avec les totaux comptables (le grand
 * livre LedgerService — AR/REVENUE/VAT_PAYABLE — reste inchangé, au niveau facture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_line_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_line_id');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('prestation_type', 30);  // plan | module | addon | adjustment | discount
            $table->string('module_key')->nullable();
            $table->dateTime('service_period_start')->nullable();
            $table->dateTime('service_period_end')->nullable(); // exclusif
            $table->integer('ht_cents');
            $table->integer('tax_rate_bps');
            $table->integer('tax_cents');            // alloué déterministe (Σ = TVA agrégée de la facture)
            $table->integer('ttc_cents');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('billable_event_id')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->unique('invoice_line_id'); // 1 ventilation par ligne
            $table->index(['company_id', 'prestation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_line_analytics');
    }
};
