<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-221: Subscription is_current uniqueness.
 *
 * MySQL trick: is_current = 1 when current, NULL when not.
 * UNIQUE(company_id, is_current) — MySQL ignores NULL in unique indexes,
 * so only ONE row per company can have is_current = 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('is_current')->nullable()->default(null)->after('cancel_at_period_end');
            $table->unique(['company_id', 'is_current']);
        });

        // Backfill: set is_current=1 for the latest active/trialing subscription per company
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('
                UPDATE subscriptions s
                INNER JOIN (
                    SELECT company_id, MAX(id) as max_id
                    FROM subscriptions
                    WHERE status IN ("active", "trialing")
                    GROUP BY company_id
                ) latest ON s.id = latest.max_id
                SET s.is_current = 1
            ');
        } else {
            // SQLite-compatible backfill
            DB::statement('
                UPDATE subscriptions
                SET is_current = 1
                WHERE id IN (
                    SELECT MAX(id)
                    FROM subscriptions
                    WHERE status IN ("active", "trialing")
                    GROUP BY company_id
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'is_current']);
            $table->dropColumn('is_current');
        });
    }
};
