<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-764 (DMS M5.2) — fiabilisation du découpage : une page SANS matricule n'est plus rattachée
 * sur la seule continuité physique. On conserve la NUMÉROTATION de page détectée (élément de preuve)
 * et une RAISON de mise en revue expliquant l'incertitude dans l'interface.
 *
 * Additif et réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslip_import_items', function (Blueprint $table) {
            $table->string('review_reason')->nullable()->after('state');
        });

        Schema::table('payslip_import_pages', function (Blueprint $table) {
            $table->unsignedSmallInteger('page_index')->nullable()->after('extracted_period');  // ex. 2 (de "2/3")
            $table->unsignedSmallInteger('page_total')->nullable()->after('page_index');         // ex. 3
        });
    }

    public function down(): void
    {
        Schema::table('payslip_import_items', function (Blueprint $table) {
            $table->dropColumn('review_reason');
        });
        Schema::table('payslip_import_pages', function (Blueprint $table) {
            $table->dropColumn(['page_index', 'page_total']);
        });
    }
};
