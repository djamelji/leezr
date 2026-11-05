<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot 4B.3-B (ADR-722) — HISTORIQUE des commandes RÉVERSIBLES (distinct de l'audit permanent
 * `company_audit_logs` et de la pile d'interface session). Permet d'exécuter des commandes INVERSES
 * validées côté serveur (annuler) et de réexécuter l'intention métier (rétablir). Additif, non destructif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_planning_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // initiateur
            $table->string('type', 40); // create_shift | move_shift | duplicate | copy | copy_week | bulk_template
            $table->json('created_shift_ids')->nullable();  // shifts créés par l'opération (undo = suppression)
            $table->json('move_payload')->nullable();       // {shift_id, before:{...,updated_at}, after:{...,updated_at}}
            $table->json('intent')->nullable();             // requête d'origine (redo = réexécution métier)
            $table->string('status', 20)->default('active'); // active | undone
            $table->dateTime('undone_at')->nullable();
            $table->dateTime('redone_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_planning_operations');
    }
};
