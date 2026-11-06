<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte Smart Planning — M5 (ADR-728) : FAVORIS personnels (user × objet workforce, scopés Company).
 *
 * Aucun système de favoris générique n'existait. Pivot simple : un favori appartient à un USER, dans une
 * COMPANY (un user multi-membership ne mélange jamais ses favoris entre tenants). favoritable_type ∈
 * {activity_preset, schedule_template, week_template}. Référence souple (pas de FK polymorphe) ; un favori
 * vers un objet désactivé/supprimé est traité proprement à la lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('favoritable_type', 40);
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'favoritable_type', 'favoritable_id'], 'wf_fav_unique');
            $table->index(['user_id', 'company_id', 'favoritable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_favorites');
    }
};
