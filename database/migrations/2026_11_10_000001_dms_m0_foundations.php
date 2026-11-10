<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-756 (DMS M0) — fondations documentaires ADDITIVES, non destructives.
 *
 * N'altère AUCUN flux existant : ajoute uniquement l'INTÉGRITÉ (file_sha256), la PUBLICATION
 * (published_state) et une table de VERSIONS (`document_versions`) pour un remplacement non
 * destructif. Les colonnes polymorphes SUJET existent déjà sur `member_documents`
 * (document_subject_type/id, parent_document_id — ADR schéma 2026-05) : on les réutilise, on
 * n'en crée pas de nouvelles. Aucune donnée supprimée, aucune contrainte durcie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('member_documents', 'file_sha256')) {
                $table->string('file_sha256', 64)->nullable()->after('mime_type')->index();
            }
            if (! Schema::hasColumn('member_documents', 'published_state')) {
                $table->string('published_state', 20)->default('published')->after('expires_at'); // draft|published|archived
            }
        });

        Schema::table('company_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('company_documents', 'file_sha256')) {
                $table->string('file_sha256', 64)->nullable()->after('mime_type')->index();
            }
            if (! Schema::hasColumn('company_documents', 'published_state')) {
                $table->string('published_state', 20)->default('published')->after('expires_at');
            }
        });

        // Historique de versions NON destructif — une ligne par version conservée d'un document.
        if (! Schema::hasTable('document_versions')) {
            Schema::create('document_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                // Référence au document du coffre (member_documents) dont c'est une version.
                $table->unsignedBigInteger('member_document_id')->index();
                // Sujet (copie figée, découplée du compte) : company|employee|user|{module-entity}.
                $table->string('subject_type', 50)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedInteger('version_no');
                $table->string('file_path');
                $table->string('file_name')->nullable();
                $table->unsignedBigInteger('file_size_bytes')->nullable();
                $table->string('mime_type')->nullable();
                $table->string('file_sha256', 64)->nullable();
                $table->string('source', 20)->default('upload'); // upload|generated|imported|signed
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['member_document_id', 'version_no']);
                $table->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::table('member_documents', function (Blueprint $table) {
            if (Schema::hasColumn('member_documents', 'file_sha256')) {
                $table->dropColumn('file_sha256');
            }
            if (Schema::hasColumn('member_documents', 'published_state')) {
                $table->dropColumn('published_state');
            }
        });
        Schema::table('company_documents', function (Blueprint $table) {
            if (Schema::hasColumn('company_documents', 'file_sha256')) {
                $table->dropColumn('file_sha256');
            }
            if (Schema::hasColumn('company_documents', 'published_state')) {
                $table->dropColumn('published_state');
            }
        });
    }
};
