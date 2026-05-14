<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_template_id')->constrained('document_templates');
            $table->unsignedInteger('template_version');
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('generated_by')->constrained('users');
            $table->dateTime('generated_at');
            $table->string('file_path');
            $table->unsignedInteger('file_size_bytes')->default(0);
            $table->json('generation_snapshot');
            $table->string('signature_status', 20)->default('none');
            $table->string('signature_provider', 50)->nullable();
            $table->json('signature_metadata')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->foreignId('member_document_id')->nullable()->constrained('member_documents')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id'], 'generated_docs_subject');
            $table->index('document_template_id');
            $table->index('member_document_id');
            $table->index('signature_status');
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
