<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOT-UX-AUTH-2 — ADR-038: Add password_set_at to users table.
 *
 * Introduces a dedicated domain field for invitation status
 * instead of deriving status from the password column.
 *
 * Backfill: existing users with a non-null password get
 * password_set_at = created_at (best approximation).
 *
 * Idempotent: guard prevents re-adding the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'password_set_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Backfill: users who already have a password are considered active
        DB::table('users')
            ->whereNotNull('password')
            ->whereNull('password_set_at')
            ->update(['password_set_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
