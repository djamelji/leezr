<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_policies', function (Blueprint $table) {
            $table->id();

            // ── Wallet ──
            $table->boolean('wallet_first')->default(true);
            $table->boolean('allow_negative_wallet')->default(false);
            $table->boolean('auto_apply_wallet_credit')->default(true);

            // ── Plan Change Timing ──
            // Values: 'immediate', 'end_of_period', 'end_of_trial'
            $table->string('upgrade_timing', 20)->default('immediate');
            $table->string('downgrade_timing', 20)->default('end_of_period');
            $table->string('proration_strategy', 20)->default('day_based'); // day_based | none

            // ── Dunning ──
            $table->smallInteger('grace_period_days')->default(3);
            $table->smallInteger('max_retry_attempts')->default(3);
            $table->json('retry_intervals_days')->nullable(); // default [1, 3, 7] set in model
            $table->string('failure_action', 30)->default('suspend'); // suspend | downgrade_to_starter | read_only

            // ── Invoice ──
            $table->smallInteger('invoice_due_days')->default(30);
            $table->string('invoice_prefix', 10)->default('INV');
            $table->unsignedInteger('invoice_next_number')->default(1);
            $table->string('credit_note_prefix', 10)->default('CN');
            $table->unsignedInteger('credit_note_next_number')->default(1);

            // ── Tax ──
            $table->string('tax_mode', 20)->default('none'); // inclusive | exclusive | none
            $table->smallInteger('default_tax_rate_bps')->default(0); // basis points (2000 = 20%)

            // ── Trial ──
            $table->smallInteger('free_trial_days')->default(0);

            // ── Addon ──
            $table->string('addon_billing_interval', 20)->default('plan_aligned'); // monthly | plan_aligned

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_policies');
    }
};
