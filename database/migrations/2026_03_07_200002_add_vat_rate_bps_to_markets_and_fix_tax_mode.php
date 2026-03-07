<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add vat_rate_bps to markets (country-level standard VAT rate)
        Schema::table('markets', function (Blueprint $table) {
            $table->unsignedInteger('vat_rate_bps')->default(0)->after('currency');
        });

        // Seed known rates: FR=2000 (20%), GB=2000 (20%)
        DB::table('markets')->where('key', 'FR')->update(['vat_rate_bps' => 2000]);
        DB::table('markets')->where('key', 'GB')->update(['vat_rate_bps' => 2000]);

        // 2. Migrate tax_mode 'none' → 'exclusive' (none no longer valid)
        DB::table('platform_billing_policies')
            ->where('tax_mode', 'none')
            ->update(['tax_mode' => 'exclusive']);
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('vat_rate_bps');
        });
    }
};
