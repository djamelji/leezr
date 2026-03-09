<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->boolean('trial_requires_payment_method')->default(true)->after('trial_plan_change_behavior');
            $table->string('trial_charge_timing', 20)->default('end_of_trial')->after('trial_requires_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->dropColumn(['trial_requires_payment_method', 'trial_charge_timing']);
        });
    }
};
