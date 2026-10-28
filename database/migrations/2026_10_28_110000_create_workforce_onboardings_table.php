<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-637 (W-F) — état PERSISTÉ du parcours d'onboarding RH, par Company (reprise multi-navigateur).
 * L'onboarding général (dashboard setup) ne couvre pas l'état multi-étapes du wizard Workforce → table
 * dédiée minimale. Tenant-scoped, 1 ligne par Company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_onboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('not_started'); // not_started|in_progress|completed
            $table->string('current_step', 40)->nullable();
            $table->json('completed_steps')->nullable();
            $table->json('deferred_steps')->nullable();
            $table->json('applied_summary')->nullable(); // provenance de la configuration appliquée
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_onboardings');
    }
};
