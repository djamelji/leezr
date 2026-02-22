<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->string('pricing_mode')->nullable()->after('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
