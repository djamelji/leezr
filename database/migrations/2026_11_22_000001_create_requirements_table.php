<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-791 (L1) — additive registry of field and document REQUIREMENTS (obligations), separate from
 * definitions (catalogues), values (field_values / DMS) and access (permissions, DocumentAccessPolicy).
 *
 * Target : two explicit nullable foreign keys instead of a polymorphic column — exactly one is set,
 * matching `target_kind` (invariant enforced by App\Core\Requirements\Requirement and tested).
 * Read only by the parallel resolver in L1 : no screen, request or form consumes it.
 * Reversible : down() drops only this table (no other data touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = Platform / marché / JobDomain / module / modèle de preset
            $t->string('target_kind', 16);                                              // field | document
            $t->foreignId('field_definition_id')->nullable()->constrained('field_definitions')->cascadeOnDelete();
            $t->foreignId('document_type_id')->nullable()->constrained('document_types')->cascadeOnDelete();
            $t->string('effect', 16)->default('require');                               // require | waive (dispense Company)
            $t->string('level', 32)->nullable();                                        // recommended | default | configurable_required | imperative
            $t->json('applicability')->nullable();                                       // job_role_keys, job_role_ids, department_ids, contract_types, markets
            $t->string('stage', 32)->nullable();                                        // hire | onboarding | ongoing
            $t->string('source', 16);                                                   // platform | market | jobdomain | preset | module | company
            $t->string('source_ref', 128)->nullable();                                  // clé de la source (module, preset, JobDomain, marché)
            $t->unsignedInteger('source_version')->nullable();
            $t->foreignId('derived_from_id')->nullable()->constrained('requirements')->nullOnDelete(); // copie Company d'un modèle (preset / module)
            $t->string('legal_reference', 255)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('status', 16)->default('active');                                // active | inactive | superseded
            $t->timestamp('effective_from')->nullable();
            $t->timestamp('effective_until')->nullable();
            $t->timestamp('customized_at')->nullable();
            $t->string('reason', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'target_kind', 'status']);
            $t->index(['source', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
    }
};
