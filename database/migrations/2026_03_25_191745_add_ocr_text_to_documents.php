<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->text('ocr_text')->nullable()->after('expires_at');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->text('ocr_text')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('ocr_text');
        });

        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropColumn('ocr_text');
        });
    }
};
