<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->string('document_subject_type', 50)->nullable()->after('id');
            $table->unsignedBigInteger('document_subject_id')->nullable()->after('document_subject_type');
            $table->string('relation_type', 30)->nullable()->after('document_subject_id'); // source, generated, signed, attachment, proof
            $table->json('generation_snapshot')->nullable()->after('relation_type');
            $table->unsignedBigInteger('parent_document_id')->nullable()->after('generation_snapshot');

            $table->index(['document_subject_type', 'document_subject_id'], 'md_subject_index');
            $table->foreign('parent_document_id')->references('id')->on('member_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropForeign(['parent_document_id']);
            $table->dropIndex('md_subject_index');
            $table->dropColumn(['document_subject_type', 'document_subject_id', 'relation_type', 'generation_snapshot', 'parent_document_id']);
        });
    }
};
