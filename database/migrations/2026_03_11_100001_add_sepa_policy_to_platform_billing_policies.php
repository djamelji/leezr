<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->boolean('allow_sepa')->default(true)->after('trial_charge_timing');
            $table->boolean('sepa_requires_trial')->default(true)->after('allow_sepa');
            $table->string('sepa_first_failure_action', 20)->default('suspend')->after('sepa_requires_trial');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->dropColumn(['allow_sepa', 'sepa_requires_trial', 'sepa_first_failure_action']);
        });
    }
};
