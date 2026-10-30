<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-4 (ADR-656) — signature PERSONNELLE contrôlée par le signataire (clé WebCrypto non-extractible).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            // Clé PUBLIQUE enregistrée (SPKI DER base64) + son identifiant stable. La clé PRIVÉE reste
            // sur l'appareil du signataire (jamais transmise à Leezr).
            $table->text('public_key_spki')->nullable()->after('metadata');
            $table->string('public_key_id', 64)->nullable()->after('public_key_spki');
            $table->string('personal_signature_alg', 40)->nullable()->after('public_key_id');
            // Preuve individuelle : signature personnelle (P1363 base64) + empreinte des données signées.
            $table->text('personal_signature')->nullable()->after('personal_signature_alg');
            $table->string('personal_signed_sha256', 64)->nullable()->after('personal_signature');
            // Challenge à usage unique (anti-rejeu) émis à l'ouverture du parcours.
            $table->string('challenge', 128)->nullable()->after('personal_signed_sha256');
            $table->dateTime('challenge_expires_at')->nullable()->after('challenge');
            $table->dateTime('challenge_used_at')->nullable()->after('challenge_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            $table->dropColumn(['public_key_spki', 'public_key_id', 'personal_signature_alg', 'personal_signature', 'personal_signed_sha256', 'challenge', 'challenge_expires_at', 'challenge_used_at']);
        });
    }
};
