<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-861 (Leezr 5.0 L7.6) — additive : how a company applies the person fields a module requires for an ACTIVITY
// (e.g. the driving licence of the Fleet module). NULL / 'everyone' = historical behaviour, unchanged.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_document_settings', 'module_obligation_scope')) {
            Schema::table('company_document_settings', function (Blueprint $table) {
                $table->string('module_obligation_scope', 16)->nullable()->after('requirements_authority');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_document_settings', 'module_obligation_scope')) {
            Schema::table('company_document_settings', fn (Blueprint $table) => $table->dropColumn('module_obligation_scope'));
        }
    }
};
