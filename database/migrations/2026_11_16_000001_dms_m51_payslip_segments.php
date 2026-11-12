<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-763 (DMS M5.1) — intégrité des bulletins MULTIPAGES + révocation.
 *
 * `payslip_import_pages` : représentation d'une PAGE source (texte/matricule/période) rattachée à
 * un segment. Un item devient un SEGMENT (plusieurs pages ordonnées) → `page_numbers` + `source_file_path`.
 * `published_payslips.revoked_at` : retrait contrôlé (bloque consultation/téléchargement, versions incluses).
 * `payslip_quota_usage` : compteurs d'usage (fichiers/pages/OCR) côté société.
 *
 * Additif et réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_import_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payslip_import_item_id')->nullable(); // segment d'appartenance
            $table->string('source_file_path');          // PDF source (multipage)
            $table->unsignedInteger('source_page_no');    // n° de page dans le PDF source
            $table->string('extracted_matricule')->nullable();
            $table->string('extracted_period')->nullable();
            $table->boolean('is_blank')->default(false);
            $table->string('role', 20)->default('body');  // body|annex|summary|unknown
            $table->timestamp('created_at')->nullable();

            $table->index(['payslip_import_batch_id']);
            $table->index(['payslip_import_item_id']);
        });

        Schema::table('payslip_import_items', function (Blueprint $table) {
            $table->string('source_file_path')->nullable()->after('file_path');
            $table->json('page_numbers')->nullable()->after('page_range'); // pages ordonnées du segment
            $table->boolean('confirmed')->default(false)->after('state');   // composition confirmée
        });

        Schema::table('published_payslips', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('superseded_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable()->after('revoked_by');
        });

        Schema::create('payslip_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('files_imported')->default(0);
            $table->unsignedBigInteger('pages_processed')->default(0);
            $table->unsignedBigInteger('ocr_pages')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_quota_usage');
        Schema::table('published_payslips', function (Blueprint $table) {
            $table->dropColumn(['revoked_at', 'revoked_by', 'revoke_reason']);
        });
        Schema::table('payslip_import_items', function (Blueprint $table) {
            $table->dropColumn(['source_file_path', 'page_numbers', 'confirmed']);
        });
        Schema::dropIfExists('payslip_import_pages');
    }
};
