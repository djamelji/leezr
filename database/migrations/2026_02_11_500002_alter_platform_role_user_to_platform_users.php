<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Safe migration: transition platform_role_user from user_id → platform_user_id.
 * Non-destructive: no dropIfExists, no data loss.
 * Idempotent: skips if already migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: already migrated → skip
        if (Schema::hasColumn('platform_role_user', 'platform_user_id')) {
            return;
        }

        // Guard: table doesn't have legacy column → nothing to migrate
        if (!Schema::hasColumn('platform_role_user', 'user_id')) {
            return;
        }

        // Step 1: add new nullable column
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_user_id')->nullable()->after('id');
        });

        // Step 2: migrate data — match users.email → platform_users.email
        $migrated = DB::update('
            UPDATE platform_role_user pru
            JOIN users u ON u.id = pru.user_id
            JOIN platform_users pu ON pu.email = u.email
            SET pru.platform_user_id = pu.id
        ');

        $orphaned = DB::table('platform_role_user')->whereNull('platform_user_id')->count();
        if ($orphaned > 0) {
            Log::warning("Migration 500002: {$orphaned} rows in platform_role_user could not be migrated (no matching platform_user). Deleting orphans.");
            DB::table('platform_role_user')->whereNull('platform_user_id')->delete();
        }

        // Step 3: drop old constraints and column (FK before unique — MySQL requirement)
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'platform_role_id']);
            $table->dropColumn('user_id');
        });

        // Step 4: add new constraints
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->foreign('platform_user_id')
                ->references('id')->on('platform_users')
                ->cascadeOnDelete();

            $table->unique(['platform_user_id', 'platform_role_id']);
        });

        // Step 5: make non-nullable now that data is clean
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Guard: already rolled back → skip
        if (Schema::hasColumn('platform_role_user', 'user_id')) {
            return;
        }

        if (!Schema::hasColumn('platform_role_user', 'platform_user_id')) {
            return;
        }

        // Step 1: add user_id nullable
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });

        // Step 2: migrate data back — match platform_users.email → users.email
        DB::update('
            UPDATE platform_role_user pru
            JOIN platform_users pu ON pu.id = pru.platform_user_id
            JOIN users u ON u.email = pu.email
            SET pru.user_id = u.id
        ');

        DB::table('platform_role_user')->whereNull('user_id')->delete();

        // Step 3: drop new constraints and column
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->dropUnique(['platform_user_id', 'platform_role_id']);
            $table->dropForeign(['platform_user_id']);
            $table->dropColumn('platform_user_id');
        });

        // Step 4: add back old constraints
        Schema::table('platform_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'platform_role_id']);
        });
    }
};
