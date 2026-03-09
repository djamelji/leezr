<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->string('trial_plan_change_behavior', 20)->default('continue_trial');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->dropColumn('trial_plan_change_behavior');
        });
    }
};
