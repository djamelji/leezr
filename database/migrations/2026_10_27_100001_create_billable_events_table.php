<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M0) — table des événements facturables, STRICTEMENT ADDITIVE ET NON BRANCHÉE.
 *
 * Créée VIDE. Aucun chemin du moteur (InvoiceIssuer/BillingRenewCommand/AddonBillingListener) n'écrit
 * ni ne lit cette table en M0. Elle sera utilisée à partir de M1 (service BillableEvent) / M3
 * (bascule mi-cycle). Schéma conforme au dossier de conception §3 + §D.
 *
 * Invariants de concurrence/idempotence encodés dès le schéma :
 *   - `idempotency_key` UNIQUE  → une activation ne crée qu'un événement, même en cas de rejeu.
 *   - `invoice_line_id` UNIQUE  → une ligne de facture ↔ au plus un événement (pas de double conso.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('currency', 3)->default('EUR');

            // Nature + provenance
            $table->string('type', 40); // plan_activation|module_activation|module_deactivation|plan_change|adjustment|discount
            $table->string('module_key')->nullable();
            $table->string('source_ref')->nullable(); // ex. id de l'événement ModuleEnabled

            // Périodes (service vs contractuelle) — bornes semi-ouvertes pour V2
            $table->dateTime('service_date')->nullable();
            $table->dateTime('service_period_start')->nullable();
            $table->dateTime('service_period_end')->nullable();   // exclusive
            $table->dateTime('contractual_period_start')->nullable();
            $table->dateTime('contractual_period_end')->nullable();

            // Snapshots figés à l'activation (reproductibilité)
            $table->integer('price_snapshot_cents')->nullable();
            $table->unsignedInteger('proration_num')->nullable();
            $table->unsignedInteger('proration_den')->nullable();
            $table->string('rounding', 12)->default('floor');
            $table->integer('amount_ht_cents')->nullable();
            $table->json('tax_context_snapshot')->nullable();
            $table->string('commercial_policy_version', 20)->nullable();

            // Machine d'état
            $table->string('status', 20)->default('eligible'); // eligible|reserved|invoiced|cancelled|compensated
            $table->string('reserved_by_run_id')->nullable();
            $table->unsignedBigInteger('invoice_line_id')->nullable();
            $table->unsignedBigInteger('compensation_of_event_id')->nullable();

            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique('invoice_line_id');
            $table->index(['company_id', 'currency', 'status']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_events');
    }
};
