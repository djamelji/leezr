<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('platform_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('subject_type', 50);
            $table->longText('body_html');
            $table->text('header_html')->nullable();
            $table->text('footer_html')->nullable();
            $table->json('variables_schema');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('paper_size', 10)->default('a4');
            $table->string('paper_orientation', 10)->default('portrait');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code', 'version'], 'templates_company_code_version');
            $table->index(['company_id', 'code', 'is_active'], 'templates_company_code_active');
            $table->index('subject_type');
            $table->index('platform_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
