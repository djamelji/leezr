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
            $table->json('compatible_jobdomains_override')->nullable()->after('sort_order_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn('compatible_jobdomains_override');
        });
    }
};
