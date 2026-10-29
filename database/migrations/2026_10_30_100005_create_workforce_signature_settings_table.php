<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-1 (ADR-651) — paramètres Signature par Company (prestataire, niveau, validité, relances, copie signée).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_signature_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('default_level', 20)->default('simple');       // simple | advanced | qualified
            $table->unsignedInteger('validity_days')->default(30);
            $table->unsignedInteger('reminder_days')->nullable();          // null = pas de relance auto
            $table->string('default_order', 20)->default('sequential');    // sequential | parallel
            $table->boolean('notify_enabled')->default(true);
            $table->boolean('send_signed_copy_to_employee')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id'], 'workforce_signature_settings_company_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_signature_settings');
    }
};
