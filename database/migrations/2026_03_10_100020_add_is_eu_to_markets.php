<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-310: Add is_eu flag to markets for EU VAT compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->boolean('is_eu')->default(false)->after('is_default');
        });

        // Mark existing EU markets
        $euKeys = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        DB::table('markets')
            ->whereIn('key', $euKeys)
            ->update(['is_eu' => true]);
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('is_eu');
        });
    }
};
