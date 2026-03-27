<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->json('ai_analysis')->nullable()->after('ocr_text');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->json('ai_analysis')->nullable()->after('ocr_text');
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('ai_analysis');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropColumn('ai_analysis');
        });
    }
};
