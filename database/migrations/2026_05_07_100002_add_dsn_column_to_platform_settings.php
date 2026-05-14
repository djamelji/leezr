<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7.1 — ADR-529
 *
 * Add 'dsn' JSON column to platform_settings for
 * Net-Entreprises credentials (encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->json('dsn')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('dsn');
        });
    }
};
