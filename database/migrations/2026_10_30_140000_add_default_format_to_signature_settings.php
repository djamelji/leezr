<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W-SIGNATURE-8 (ADR-660) — mode de signature par défaut d'une Company (vocabulaire métier). Additif.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_signature_settings', function (Blueprint $table) {
            // Mode métier : 'personal_digital' | 'demonstration' (mappé vers un required_format technique).
            $table->string('default_format', 40)->nullable()->after('default_level');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_signature_settings', function (Blueprint $table) {
            $table->dropColumn('default_format');
        });
    }
};
