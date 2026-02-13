<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password Lifecycle — ADR-037 / LOT-AUTH-5.
 *
 * - Make users.password nullable (invitation-first flow)
 * - Make platform_users.password nullable (invitation-first flow)
 * - Create platform_password_reset_tokens table (dual-scope broker)
 *
 * Idempotent: guards prevent re-running.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: users.password → nullable
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        // Step 2: platform_users.password → nullable
        Schema::table('platform_users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        // Step 3: platform_password_reset_tokens (mirrors password_reset_tokens)
        if (!Schema::hasTable('platform_password_reset_tokens')) {
            Schema::create('platform_password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_tokens');

        Schema::table('platform_users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
