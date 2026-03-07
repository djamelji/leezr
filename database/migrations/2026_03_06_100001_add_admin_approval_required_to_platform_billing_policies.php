<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->boolean('admin_approval_required')->default(false)->after('free_trial_days');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_policies', function (Blueprint $table) {
            $table->dropColumn('admin_approval_required');
        });
    }
};
