<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-593 — provenance of the contracted-price snapshot. The current CATALOG price must never be
 * treated as proof of the historical contracted price. We record WHERE each snapshot came from:
 *   subscribed | plan_change | migrated | reconstructed | admin_confirmed  → RELIABLE
 *   provisional (catalog backfill, unverified) | null                       → NOT reliable
 *
 * Backfill: existing snapshots were set from the ADR-587 catalog backfill, so they are PROVISIONAL
 * (unverified) — never presented as historically verified. Additive, no amount changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('plan_price_source', 20)->nullable()->after('plan_price_cents');
        });

        // Any existing snapshot predates provenance tracking and came from the catalog backfill →
        // mark it PROVISIONAL (requires administrative confirmation before it is trusted).
        DB::table('subscriptions')
            ->whereNotNull('plan_price_cents')
            ->whereNull('plan_price_source')
            ->update(['plan_price_source' => 'provisional']);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('plan_price_source');
        });
    }
};
