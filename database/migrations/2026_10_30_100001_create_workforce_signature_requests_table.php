<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-1 (ADR-651) — demandes de signature électronique sur un document RH émis.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Document source : DOIT être un GeneratedDocument persisté (émis) — jamais un aperçu.
            $table->foreignId('generated_document_id')->constrained('generated_documents')->cascadeOnDelete();
            // Copie du sujet du document (scope manager/collaborateur sans re-jointure).
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Empreinte du PDF source au moment de la demande (intégrité).
            $table->string('document_sha256', 64);
            $table->unsignedInteger('document_version')->nullable();
            $table->foreignId('initiator_id')->constrained('users');
            $table->string('provider', 50);
            $table->string('signature_level', 20);          // simple | advanced | qualified
            $table->string('signing_order', 20)->default('sequential'); // sequential | parallel
            $table->string('status', 20)->default('draft');  // draft|pending|partially_signed|completed|declined|cancelled|expired|failed
            $table->string('provider_reference')->nullable(); // id d'enveloppe côté prestataire
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            // Artefact signé final (séparé du source immuable).
            $table->string('signed_document_path')->nullable();
            $table->string('signed_document_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['generated_document_id']);
            $table->index(['company_id', 'subject_type', 'subject_id'], 'sig_requests_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_requests');
    }
};
