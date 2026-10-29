<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-1 (ADR-651) — signataires d'une demande (ordre séquentiel/parallèle, lien sécurisé par token haché).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_signature_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained('workforce_signature_requests')->cascadeOnDelete();
            $table->unsignedInteger('order')->default(1);
            $table->string('role', 30);                       // company_representative | employee | external
            $table->string('party_type', 20)->nullable();     // user | employee (interne) ; null = externe
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('required_auth_method', 30)->nullable(); // niveau exigé
            $table->string('auth_method', 30)->nullable();          // méthode RÉELLEMENT employée (après action)
            $table->string('status', 20)->default('pending');       // pending | invited | signed | declined
            // Lien sécurisé : on stocke le HASH du token opaque, jamais le token en clair.
            $table->string('token_hash', 64)->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('token_revoked_at')->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('acted_at')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->string('decline_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['signature_request_id', 'order']);
            $table->index(['company_id']);
            $table->index(['token_hash'], 'sig_signers_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_signers');
    }
};
