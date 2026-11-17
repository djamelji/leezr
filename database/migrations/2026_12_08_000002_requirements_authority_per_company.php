<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-818 — explicit, per-company authority of person requirements : `role` (historical, default for every
 * company) or `position` (target model, ADR-817). Additive ; switching back restores the historical
 * computation (no data migrated, no request regenerated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_document_settings', 'requirements_authority')) {
                $table->string('requirements_authority', 16)->default('role')->after('ai_features');
                $table->timestamp('requirements_authority_at')->nullable()->after('requirements_authority');
                $table->unsignedBigInteger('requirements_authority_by')->nullable()->after('requirements_authority_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_document_settings', 'requirements_authority')) {
                $table->dropColumn(['requirements_authority', 'requirements_authority_at', 'requirements_authority_by']);
            }
        });
    }
};
