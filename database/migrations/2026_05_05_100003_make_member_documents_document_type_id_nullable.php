<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            // Generated documents (payslips, contracts) don't always have a DocumentType
            $table->unsignedBigInteger('document_type_id')->nullable()->change();
            // Generated documents have no uploader (system-generated)
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_type_id')->nullable(false)->change();
            $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
        });
    }
};
