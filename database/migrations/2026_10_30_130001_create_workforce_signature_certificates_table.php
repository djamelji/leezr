<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-7 (ADR-659) — historique/traçabilité des certificats émis par l'AC interne DEV.
// Append-only côté métier : émission, validité, révocation. Jamais d'effacement (vérification d'anciens
// documents). La révocation est un changement de statut, pas une suppression.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_signature_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->nullable()->constrained('workforce_signature_requests')->nullOnDelete();
            $table->foreignId('signature_signer_id')->nullable()->constrained('workforce_signature_signers')->nullOnDelete();

            $table->string('serial', 64);
            $table->string('fingerprint_sha256', 64);
            $table->string('public_key_id', 64);              // empreinte SPKI (lien à la clé réelle)
            $table->string('subject_cn');
            $table->string('issuer_dn');
            $table->dateTime('not_before');
            $table->dateTime('not_after');

            $table->string('status', 20)->default('issued');  // issued | revoked | expired
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            // Identité/authentification RÉELLEMENT constatées (honnêteté du niveau de confiance).
            $table->string('auth_method', 40)->nullable();    // ex : token_link
            $table->string('identity_verification', 60)->default('declared'); // declared | none_regulatory
            $table->text('certificate_pem');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'public_key_id']);
            $table->unique(['fingerprint_sha256']);
            $table->index(['serial']);
            $table->index(['signature_signer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_certificates');
    }
};
