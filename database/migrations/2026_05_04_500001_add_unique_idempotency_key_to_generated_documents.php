<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropIndex(['idempotency_key']);
            $table->unique(['company_id', 'idempotency_key'], 'generated_docs_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropUnique('generated_docs_idempotency_unique');
            $table->index('idempotency_key');
        });
    }
};
