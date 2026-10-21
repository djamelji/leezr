<?php

use App\Core\Plans\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-587 — Subscription contracted-price snapshot. Protects existing subscribers from public price
 * changes: the renewal honours `plan_price_cents` (the price contracted for the subscription's
 * interval) instead of re-reading the live catalog. Additive + non-destructive: the backfill LOCKS
 * each existing active/trialing subscription at its CURRENT catalog price (no billed amount changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->bigInteger('plan_price_cents')->nullable()->after('interval'); // contracted price, cents
        });

        // Backfill: lock existing subscriptions at their current catalog price (protective, no amount change).
        $prices = Plan::all()->mapWithKeys(fn ($p) => [$p->key => ['monthly' => $p->price_monthly, 'yearly' => $p->price_yearly]]);
        DB::table('subscriptions')->whereNull('plan_price_cents')->whereIn('status', ['active', 'trialing', 'past_due'])
            ->orderBy('id')->chunkById(500, function ($rows) use ($prices) {
                foreach ($rows as $s) {
                    $p = $prices[$s->plan_key] ?? null;
                    if (! $p) {
                        continue;
                    }
                    $cents = ($s->interval === 'yearly') ? $p['yearly'] : $p['monthly'];
                    if ($cents !== null) {
                        DB::table('subscriptions')->where('id', $s->id)->update(['plan_price_cents' => $cents]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('plan_price_cents');
        });
    }
};
