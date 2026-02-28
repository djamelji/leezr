<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('theme_preference', ['light', 'dark', 'system'])
                ->default('system')
                ->after('avatar');
        });

        Schema::table('platform_users', function (Blueprint $table) {
            $table->enum('theme_preference', ['light', 'dark', 'system'])
                ->default('system')
                ->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme_preference');
        });

        Schema::table('platform_users', function (Blueprint $table) {
            $table->dropColumn('theme_preference');
        });
    }
};
