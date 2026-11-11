<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-759 (DMS M2) — fait évoluer `document_requests` en un vrai cycle de demande + traçabilité :
 *  - champs de demande : message, échéance, validation requise ;
 *  - rattachement SUJET (permet une demande à un Employee SANS compte, user_id nullable) ;
 *  - lien vers la VERSION validée (demande acceptée ↔ version) ;
 *  - table d'ÉVÉNEMENTS append-only `document_request_events` : chaque transition (demandé, déposé,
 *    en vérification, accepté, refusé+motif, à remplacer, annulé) est conservée → aucune preuve
 *    supprimée, historique complet même après re-soumission.
 *
 * Additif et réversible. Backfill du sujet depuis user_id pour les demandes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('document_subject_type', 50)->nullable()->after('company_id');
            $table->unsignedBigInteger('document_subject_id')->nullable()->after('document_subject_type');
            $table->text('message')->nullable()->after('status');
            $table->date('due_date')->nullable()->after('message');
            $table->boolean('requires_validation')->default(true)->after('due_date');
            $table->unsignedBigInteger('approved_version_id')->nullable()->after('requires_validation');
        });

        // user_id devient nullable (demande à un sujet employee sans compte).
        Schema::table('document_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Backfill du sujet pour les demandes existantes (rapprochement EXPLICITE via user_id).
        DB::table('document_requests')->whereNull('document_subject_type')->whereNotNull('user_id')
            ->update(['document_subject_type' => 'user', 'document_subject_id' => DB::raw('user_id')]);

        // On CONSERVE l'ancien unique (company, user_id, type) : user_id étant nullable, il reste
        // non bloquant pour les sujets sans compte (NULLs distincts) et cohérent pour les sujets
        // user. On ajoute l'unique par SUJET pour les employés sans compte.
        Schema::table('document_requests', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'document_subject_type', 'document_subject_id', 'document_type_id'],
                'dr_company_subject_type_unique',
            );
            $table->index(['document_subject_type', 'document_subject_id'], 'dr_subject_idx');
        });

        // Journal append-only des transitions (traçabilité complète, aucune preuve supprimée).
        Schema::create('document_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);                 // requested|submitted|in_review|approved|rejected|to_replace|cancelled
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();            // motif de refus, message...
            $table->unsignedBigInteger('version_id')->nullable(); // version documentaire liée
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['document_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_events');

        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropUnique('dr_company_subject_type_unique');
            $table->dropIndex('dr_subject_idx');
            $table->dropColumn([
                'document_subject_type', 'document_subject_id', 'message',
                'due_date', 'requires_validation', 'approved_version_id',
            ]);
            // user_id : laissé nullable (down non destructif).
        });
    }
};
