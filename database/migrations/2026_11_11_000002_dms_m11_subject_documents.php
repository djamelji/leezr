<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-758 (DMS M1.1) — permet d'attacher un document à un Employee SANS compte User, via le
 * SUJET documentaire explicite, sans dupliquer le stockage ni créer de compte artificiel :
 *  - `member_documents.user_id` devient NULLABLE (un doc de sujet employee n'a pas d'user_id) ;
 *  - index UNIQUE par sujet `(company_id, document_subject_type, document_subject_id,
 *    document_type_id)` → upsert déterministe pour les sujets sans user (les lignes user restent
 *    aussi protégées par l'unique historique `(company_id, user_id, document_type_id)`).
 *
 * Additif et réversible. Aucune donnée supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('member_documents', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'document_subject_type', 'document_subject_id', 'document_type_id'],
                'md_company_subject_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropUnique('md_company_subject_type_unique');
        });
        // user_id : laissé nullable (down non destructif ; restaurer NOT NULL casserait les docs
        // de sujet employee). Sans danger.
    }
};
