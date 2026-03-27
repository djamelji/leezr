<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-413: Add ai_features to company_document_settings
 * and ai_insights to member_documents + company_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->json('ai_features')->nullable()->after('remind_after_days');
        });

        Schema::table('member_documents', function (Blueprint $table) {
            $table->json('ai_insights')->nullable()->after('ai_analysis');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->json('ai_insights')->nullable()->after('ai_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->dropColumn('ai_features');
        });

        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('ai_insights');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropColumn('ai_insights');
        });
    }
};
