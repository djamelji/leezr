<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M4) — watermark anti-double-facturation + idempotence de BillingRun. ADDITIVES.
 *
 * `billing_watermarks` : par (subscription, prestation, devise), la borne EXCLUSIVE jusqu'à laquelle
 * une prestation (plan ou module) est déjà facturée (`billed_through`). Empêche de refacturer une
 * période déjà couverte (V1 ou V2). Un watermark absent/non fiable DOIT bloquer l'émission.
 *
 * `billing_runs` : identité UNIQUE d'un run (company, devise, subscription, période) → deux runs
 * concurrents = une seule facture (la 2ᵉ insertion viole l'unicité et récupère l'existante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_watermarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('prestation_key', 100); // 'plan' | module_key — longueur d'index sûre (utf8mb4)
            $table->string('currency', 3)->default('EUR');
            $table->dateTime('billed_through')->nullable(); // borne EXCLUSIVE couverte ; null = inconnu → bloque
            $table->string('source_model_version', 4)->default('v2');
            $table->timestamps();

            $table->unique(['subscription_id', 'prestation_key', 'currency'], 'bw_sub_prestation_currency_uq');
            $table->index('company_id');
        });

        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_identity');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('currency', 3)->default('EUR');
            $table->dateTime('period_start');
            $table->dateTime('period_end')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('status', 20)->default('emitted'); // emitted | failed
            $table->timestamps();

            $table->unique('run_identity');     // idempotence : 1 facture par run
            $table->index(['company_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
        Schema::dropIfExists('billing_watermarks');
    }
};
