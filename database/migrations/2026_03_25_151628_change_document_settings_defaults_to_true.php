<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-408: Change automation defaults to true for new companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->boolean('auto_renew_enabled')->default(true)->change();
            $table->boolean('auto_remind_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->boolean('auto_renew_enabled')->default(false)->change();
            $table->boolean('auto_remind_enabled')->default(false)->change();
        });
    }
};
