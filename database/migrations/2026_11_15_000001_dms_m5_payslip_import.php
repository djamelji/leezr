<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-762 (DMS M5) — import, reconnaissance et distribution des bulletins externes. Capacité DMS
 * AUTONOME (indépendante du moteur de génération de paie). Additif et réversible.
 *
 * `payslip_import_batches` : un lot importé (fichiers + période + état). `payslip_import_items` :
 * un segment (bulletin) détecté, son matching, sa confiance, son état de revue/publication, et le
 * lien vers le document publié (traçabilité du lot d'origine). Les preuves de correspondance sont
 * minimisées (pas de données salariales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('period')->nullable();          // période globale si connue (YYYY-MM)
            $table->string('status', 20)->default('uploaded'); // uploaded|processing|review|publishing|published|failed
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_published')->default(0);
            $table->json('source_files')->nullable();       // noms d'origine + tailles (métadonnées)
            $table->json('errors')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('payslip_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');                    // segment (PDF 1 bulletin) — stockage privé
            $table->string('file_sha256', 64)->nullable();
            $table->string('page_range')->nullable();       // ex. "1" ou "2-3"
            $table->string('extracted_matricule')->nullable();
            $table->string('extracted_period')->nullable();
            $table->string('extracted_name')->nullable();
            $table->unsignedBigInteger('matched_employee_id')->nullable();
            $table->string('match_confidence', 20)->default('none'); // certain|probable|ambiguous|none
            $table->json('match_candidates')->nullable();   // ids candidats (preuve minimisée)
            $table->string('state', 20)->default('pending'); // pending|matched|review|excluded|published|failed
            $table->unsignedBigInteger('published_member_document_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['payslip_import_batch_id', 'state']);
            $table->index(['company_id', 'matched_employee_id']);
        });

        // Bulletins PUBLIÉS : série par (sujet, période). Un rectificatif crée une nouvelle version
        // (l'ancienne est conservée, jamais écrasée). Classé `payroll` → accès gouverné par la policy.
        Schema::create('published_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_subject_type', 50);
            $table->unsignedBigInteger('document_subject_id');
            $table->string('period');                       // YYYY-MM
            $table->string('file_path');                    // stockage privé
            $table->string('file_name');
            $table->string('file_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('source', 30)->default('external_import'); // external_import | leezr_generated
            $table->unsignedBigInteger('source_item_id')->nullable(); // traçabilité du lot
            $table->unsignedInteger('version_no')->default(1);
            $table->timestamp('superseded_at')->nullable();  // remplacé par un rectificatif
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'document_subject_type', 'document_subject_id', 'period'], 'pp_subject_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_payslips');
        Schema::dropIfExists('payslip_import_items');
        Schema::dropIfExists('payslip_import_batches');
    }
};
