<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_statuses', function (Blueprint $table) {
            $table->boolean('is_vat_applicable')->default(true)->after('description');
            $table->decimal('vat_rate', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('legal_statuses', function (Blueprint $table) {
            $table->dropColumn('is_vat_applicable');
            $table->decimal('vat_rate', 5, 2)->default(0)->change();
        });
    }
};
