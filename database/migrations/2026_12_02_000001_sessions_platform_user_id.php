<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-801.1 — ADDITIVE. A Platform session records its identity in its own column, with its own FK
 * (platform_users), instead of borrowing sessions.user_id (FK users). No existing row is modified or
 * deleted : rows rewrite their identity columns on their next request (ScopedDatabaseSessionHandler).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sessions', 'platform_user_id')) {
            return;
        }
        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('platform_user_id')->nullable()->after('user_id')->index()
                ->constrained('platform_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sessions', 'platform_user_id')) {
            return;
        }
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_user_id');
        });
    }
};
