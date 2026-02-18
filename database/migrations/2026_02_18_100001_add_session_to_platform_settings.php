<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->json('session')->nullable()->after('theme');
        });

        // Cleanup orphan module row from replaced ThemeModule
        DB::table('platform_modules')->where('key', 'platform.theme')->delete();
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('session');
        });
    }
};
