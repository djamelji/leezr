<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-7 (ADR-659) — certificat X.509 individuel du signataire (AC interne DEV) + préparation
// de signature PAdES externe (to_sign expirant). Additif, non destructif.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            // Certificat individuel ACTIF du signataire, lié à sa clé publique WebCrypto réelle.
            $table->text('signer_certificate_pem')->nullable()->after('challenge_used_at');
            $table->string('signer_cert_serial', 64)->nullable()->after('signer_certificate_pem');
            $table->string('signer_cert_fingerprint', 64)->nullable()->after('signer_cert_serial');
            $table->string('signer_cert_issuer', 255)->nullable()->after('signer_cert_fingerprint');
            $table->dateTime('signer_cert_not_before')->nullable()->after('signer_cert_issuer');
            $table->dateTime('signer_cert_not_after')->nullable()->after('signer_cert_not_before');
            $table->dateTime('signer_cert_revoked_at')->nullable()->after('signer_cert_not_after');

            // Préparation PAdES externe (phase 1) : données exactes à signer + révision de base,
            // expirante et à usage unique (anti-rejeu / anti-réutilisation sur d'autres octets).
            $table->json('signing_prep')->nullable()->after('signer_cert_revoked_at');
            $table->string('signing_base_sha256', 64)->nullable()->after('signing_prep');
            $table->dateTime('signing_prep_expires_at')->nullable()->after('signing_base_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            $table->dropColumn([
                'signer_certificate_pem', 'signer_cert_serial', 'signer_cert_fingerprint', 'signer_cert_issuer',
                'signer_cert_not_before', 'signer_cert_not_after', 'signer_cert_revoked_at',
                'signing_prep', 'signing_base_sha256', 'signing_prep_expires_at',
            ]);
        });
    }
};
