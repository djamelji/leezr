<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M5) — preuve de couverture par INTERVALLES facturés + flag de facturation immédiate.
 * ADDITIVES.
 *
 * `billing_covered_intervals` : chaque période `[start, end)` réellement facturée pour une prestation
 * (plan|module) d'un abonnement. Le simple `billed_through` ne suffit pas aux cas disjoints
 * (immédiat hors cycle, désactivation/réactivation, périodes multiples) : on confronte toute nouvelle
 * période aux intervalles déjà couverts (détection de chevauchement) → anti-double-facturation réel.
 *
 * `billable_events.bill_immediately` : marque un événement à facturer HORS CYCLE (politique
 * contractuelle), par opposition au différé (défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_covered_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('prestation_key', 100); // 'plan' | module_key
            $table->string('currency', 3)->default('EUR');
            $table->dateTime('period_start');
            $table->dateTime('period_end'); // exclusif
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_line_id')->nullable();
            $table->string('source_model_version', 4)->default('v2');
            $table->timestamps();

            $table->index(['subscription_id', 'prestation_key', 'currency'], 'bci_sub_prestation_currency_idx');
            $table->index('company_id');
        });

        Schema::table('billable_events', function (Blueprint $table) {
            $table->boolean('bill_immediately')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('billable_events', function (Blueprint $table) {
            $table->dropColumn('bill_immediately');
        });
        Schema::dropIfExists('billing_covered_intervals');
    }
};
