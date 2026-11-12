<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-761 (DMS M4) — campagnes documentaires collectives. Réutilise `document_requests` /
 * `DocumentRequestService` : chaque destinataire conserve une DEMANDE INDIVIDUELLE réelle. La
 * table `document_campaign_recipients` est le SNAPSHOT (population figée au lancement) → traçabilité.
 *
 * Additif et réversible. Aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('message')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('requires_validation')->default(true);
            $table->json('targeting');                 // {mode, department_ids, person_refs, filters...}
            $table->json('reminder_policy')->nullable(); // surcharge la politique de relance (sinon société)
            $table->string('status', 20)->default('draft'); // draft|launching|active|completed|cancelled
            $table->unsignedInteger('recipients_total')->default(0);
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('document_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('document_subject_type', 50);
            $table->unsignedBigInteger('document_subject_id');
            $table->unsignedBigInteger('document_request_id')->nullable(); // demande individuelle liée
            $table->string('state', 20)->default('pending'); // pending|created|failed
            $table->text('error')->nullable();
            $table->timestamps();

            // Unicité : une personne figure une seule fois par campagne (anti-doublon au lancement).
            $table->unique(['document_campaign_id', 'document_subject_type', 'document_subject_id'], 'dcr_campaign_subject_unique');
            $table->index(['document_campaign_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_campaign_recipients');
        Schema::dropIfExists('document_campaigns');
    }
};
