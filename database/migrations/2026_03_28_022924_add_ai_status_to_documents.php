<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->string('ai_status', 20)->nullable()->after('ai_insights')
                ->comment('pending|processing|completed|failed');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->string('ai_status', 20)->nullable()->after('ai_insights')
                ->comment('pending|processing|completed|failed');
        });

        // Backfill: documents with ai_analysis → completed, with file but no analysis → pending
        DB::table('member_documents')
            ->whereNotNull('ai_analysis')
            ->update(['ai_status' => 'completed']);

        DB::table('member_documents')
            ->whereNotNull('file_path')
            ->whereNull('ai_analysis')
            ->update(['ai_status' => 'pending']);

        DB::table('company_documents')
            ->whereNotNull('ai_analysis')
            ->update(['ai_status' => 'completed']);

        DB::table('company_documents')
            ->whereNotNull('file_path')
            ->whereNull('ai_analysis')
            ->update(['ai_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('ai_status');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropColumn('ai_status');
        });
    }
};
