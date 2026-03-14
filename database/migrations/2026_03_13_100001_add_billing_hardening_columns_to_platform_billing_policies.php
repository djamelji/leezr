<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-341: Centralize hardcoded billing parameters into PlatformBillingPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->string('addon_deactivation_timing', 20)->default('end_of_period')->after('addon_billing_interval');
            $table->unsignedSmallInteger('trial_expiry_notification_days')->default(3)->after('trial_charge_timing');
            $table->unsignedSmallInteger('payment_method_expiry_check_days')->default(30)->after('trial_expiry_notification_days');
            $table->unsignedSmallInteger('reconciliation_lookback_days')->default(30)->after('payment_method_expiry_check_days');
            $table->string('default_billing_interval', 10)->default('monthly')->after('reconciliation_lookback_days');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->dropColumn([
                'addon_deactivation_timing',
                'trial_expiry_notification_days',
                'payment_method_expiry_check_days',
                'reconciliation_lookback_days',
                'default_billing_interval',
            ]);
        });
    }
};
