<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-9 (ADR-661) — attribution de la signature à une IDENTITÉ, pas à la seule possession du
// lien. Niveau d'assurance d'identité + compte authentifié lié + historique d'enrôlement. Additif.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            // Compte Leezr RÉELLEMENT authentifié lors de l'enrôlement/signature (null = token seul/externe).
            if (! Schema::hasColumn('workforce_signature_signers', 'authenticated_user_id')) {
                $table->unsignedBigInteger('authenticated_user_id')->nullable()->after('signing_prep_expires_at');
            }
            // Niveau d'assurance d'identité HONNÊTE : declared | link_only | account_authenticated.
            if (! Schema::hasColumn('workforce_signature_signers', 'identity_assurance')) {
                $table->string('identity_assurance', 30)->default('link_only')->after('authenticated_user_id');
            }
            if (! Schema::hasColumn('workforce_signature_signers', 'reenrollment_count')) {
                $table->unsignedInteger('reenrollment_count')->default(0)->after('identity_assurance');
            }
        });

        // Historique d'enrôlement (append-only métier) : chaque association de clé, avec son assurance.
        if (Schema::hasTable('workforce_signature_enrollments')) {
            return;
        }
        Schema::create('workforce_signature_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_signer_id')->constrained('workforce_signature_signers')->cascadeOnDelete();
            $table->string('public_key_id', 64);
            $table->string('assurance', 30);                 // niveau au moment de l'enrôlement
            $table->unsignedBigInteger('authenticated_user_id')->nullable();
            $table->string('reason', 60)->default('initial'); // initial | device_change_reenrollment | admin_reset
            $table->string('ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['signature_signer_id', 'created_at']);
            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_enrollments');
        Schema::table('workforce_signature_signers', function (Blueprint $table) {
            $table->dropColumn(['authenticated_user_id', 'identity_assurance', 'reenrollment_count']);
        });
    }
};
