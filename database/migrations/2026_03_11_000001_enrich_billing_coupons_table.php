<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_coupons', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->unsignedInteger('max_uses_per_company')->nullable()->after('used_count');
            $table->json('applicable_billing_cycles')->nullable()->after('applicable_plan_keys');
            $table->json('applicable_addon_keys')->nullable()->after('applicable_billing_cycles');
            $table->enum('addon_mode', ['include', 'exclude'])->nullable()->after('applicable_addon_keys');
            $table->unsignedInteger('duration_months')->nullable()->after('addon_mode');
            $table->boolean('first_purchase_only')->default(false)->after('duration_months');
            $table->dropColumn('min_plan_level');
        });
    }

    public function down(): void
    {
        Schema::table('billing_coupons', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'max_uses_per_company',
                'applicable_billing_cycles',
                'applicable_addon_keys',
                'addon_mode',
                'duration_months',
                'first_purchase_only',
            ]);
            $table->unsignedInteger('min_plan_level')->nullable()->after('used_count');
        });
    }
};
