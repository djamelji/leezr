<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('coupon_id')
                ->nullable()
                ->after('metadata')
                ->constrained('billing_coupons')
                ->nullOnDelete();
            $table->unsignedInteger('coupon_months_remaining')->nullable()->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_months_remaining');
        });
    }
};
