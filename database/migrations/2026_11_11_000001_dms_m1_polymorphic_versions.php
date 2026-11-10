<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-757 (DMS M1) — rend `document_versions` POLYMORPHE pour couvrir le versioning
 * non destructif de TOUS les documents (member ET company, et futures entités module),
 * sans seconde table. Additif/réversible. `member_document_id` devient nullable (les
 * versions de documents company n'y sont pas rattachées).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('document_versions', 'documentable_type')) {
                $table->string('documentable_type', 191)->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('document_versions', 'documentable_id')) {
                $table->unsignedBigInteger('documentable_id')->nullable()->after('documentable_type');
            }
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->index(['documentable_type', 'documentable_id'], 'docver_documentable_idx');
        });

        // member_document_id : nullable (company versions n'y sont pas liées)
        Schema::table('document_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('member_document_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropIndex('docver_documentable_idx');
        });
        Schema::table('document_versions', function (Blueprint $table) {
            if (Schema::hasColumn('document_versions', 'documentable_type')) {
                $table->dropColumn('documentable_type');
            }
            if (Schema::hasColumn('document_versions', 'documentable_id')) {
                $table->dropColumn('documentable_id');
            }
        });
        // member_document_id : on NE restaure PAS le NOT NULL (down non destructif ; les lignes
        // polymorphes company deviendraient invalides). Le laisser nullable est sans danger.
    }
};
