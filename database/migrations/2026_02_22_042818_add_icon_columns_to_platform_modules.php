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
            $table->string('icon_type')->nullable()->after('sort_order_override');
            $table->string('icon_name')->nullable()->after('icon_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn(['icon_type', 'icon_name']);
        });
    }
};
