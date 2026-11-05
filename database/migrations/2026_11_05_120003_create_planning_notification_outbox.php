<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot 3 complément (ADR-717) — outbox transactionnel des notifications de planning.
 *
 * Ce n'est PAS un 2ᵉ moteur de notification : c'est un tampon de DURABILITÉ qui enregistre l'INTENTION de
 * notifier DANS la transaction du changement de planning (atomique). Un flush (post-commit immédiat +
 * commande planifiée de sécurité) consomme les lignes en réutilisant PlanningNotifier. Idempotent via
 * l'entity_key existant → pas de doublon même si le flush rejoue. Si le process meurt après le commit mais
 * avant l'envoi, la ligne survit et sera envoyée au prochain flush.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_planning_notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('topic_key', 80);
            $table->boolean('is_change')->default(false);
            $table->string('event_ref')->nullable();
            $table->json('groups'); // [{employee_id, from, to, count}]
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_planning_notification_outbox');
    }
};
