<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-740 — IMAP import idempotence.
 *
 * Root cause of the thread explosion (22 091 threads from ~14 real messages):
 *  - dedup relied ONLY on Message-ID, and only registered on a SUCCESSFUL log insert;
 *  - the thread was created BEFORE the (failing) log insert → orphan threads;
 *  - messages without Message-ID got a RANDOM UUID → re-imported every cycle.
 *
 * Fix: a persistent, deterministic identity per (imap_account, uidvalidity, folder, uid),
 * enforced by a UNIQUE index (concurrency guard). message_id is no longer globally unique
 * (the SAME Message-ID can legitimately be received by two different accounts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('imap_account')->nullable()->after('external_id');
            $table->unsignedBigInteger('imap_uidvalidity')->nullable()->after('imap_account');
            $table->unsignedBigInteger('imap_uid')->nullable()->after('imap_uidvalidity');
            $table->string('imap_folder', 64)->nullable()->after('imap_uid');
        });

        // Persistent IMAP identity — concurrency-safe idempotence guard.
        // All-NULL for outbound/legacy rows; NULLs are treated as distinct so no collision.
        Schema::table('email_logs', function (Blueprint $table) {
            $table->unique(
                ['imap_account', 'imap_uidvalidity', 'imap_folder', 'imap_uid'],
                'uq_email_logs_imap_identity'
            );
        });

        // message_id is no longer a GLOBAL unique (two accounts may receive the same
        // Message-ID). Keep it indexed for In-Reply-To / References threading lookups.
        try {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropUnique('email_logs_message_id_unique');
            });
        } catch (\Throwable $e) {
            // Unique index absent or named differently in this env — non-fatal.
        }

        try {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->index('message_id', 'idx_email_logs_message_id');
            });
        } catch (\Throwable $e) {
            // Index already present — non-fatal.
        }
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropUnique('uq_email_logs_imap_identity');
            $table->dropIndex('idx_email_logs_message_id');
            $table->dropColumn(['imap_account', 'imap_uidvalidity', 'imap_uid', 'imap_folder']);
        });
        // Restore the original global unique (best-effort; requires no duplicates).
        try {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->unique('message_id', 'email_logs_message_id_unique');
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
