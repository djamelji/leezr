<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-1 (ADR-651) — journal de preuves APPEND-ONLY (chaque étape significative).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained('workforce_signature_requests')->cascadeOnDelete();
            $table->foreignId('signature_signer_id')->nullable()->constrained('workforce_signature_signers')->nullOnDelete();
            $table->string('type', 40);            // created|sent|invited|viewed|signed|declined|cancelled|expired|completed|failed|webhook_received|verification_passed|verification_failed
            $table->string('actor_type', 20);      // user | signer | system | provider
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('document_sha256', 64)->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('verification_result', 20)->nullable(); // passed | failed | na
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['signature_request_id', 'occurred_at'], 'sig_events_request_time_idx');
            $table->index(['company_id'], 'sig_events_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_events');
    }
};
