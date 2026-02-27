<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('interval', 10)->default('monthly')->after('plan_key'); // monthly | yearly
            $table->timestamp('trial_ends_at')->nullable()->after('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false)->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['interval', 'trial_ends_at', 'cancel_at_period_end']);
        });
    }
};
