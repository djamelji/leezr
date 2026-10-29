<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-1 (ADR-651) — idempotence des webhooks prestataire (insert unique provider+event_id).
// Table dédiée Signature (ne touche pas la table webhook_events de Billing).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('event_id');
            $table->string('event_type')->nullable();
            $table->string('status', 20)->default('received'); // received | processing | processed | ignored | failed
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'signature_webhook_events_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_webhook_events');
    }
};
