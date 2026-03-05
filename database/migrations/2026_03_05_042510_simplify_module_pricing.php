<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-206: Module Pricing Simplification.
 *
 * Removes pricing_mode (redundant with jobdomain.default_modules).
 * Consolidates pricing_model + pricing_metric + pricing_params into addon_pricing JSON.
 * Effective pricing is now derived: core→included, internal→internal,
 * in_defaults→included, addon_pricing≠null→addon, else→contact_sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add addon_pricing JSON column
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->json('addon_pricing')->nullable()->after('notes');
        });

        // 2. Migrate existing addon-priced modules into addon_pricing JSON
        DB::table('platform_modules')
            ->where('pricing_mode', 'addon')
            ->orderBy('id')
            ->each(function ($pm) {
                DB::table('platform_modules')
                    ->where('id', $pm->id)
                    ->update([
                        'addon_pricing' => json_encode([
                            'pricing_model' => $pm->pricing_model,
                            'pricing_metric' => $pm->pricing_metric,
                            'pricing_params' => json_decode($pm->pricing_params, true),
                        ]),
                    ]);
            });

        // 3. Drop old columns
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'pricing_model', 'pricing_metric', 'pricing_params']);
        });
    }

    public function down(): void
    {
        // 1. Re-add old columns
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->string('pricing_mode')->nullable()->after('sort_order');
            $table->string('pricing_model')->nullable()->after('is_sellable');
            $table->string('pricing_metric')->nullable()->after('pricing_model');
            $table->json('pricing_params')->nullable()->after('pricing_metric');
        });

        // 2. Restore from addon_pricing
        DB::table('platform_modules')
            ->whereNotNull('addon_pricing')
            ->orderBy('id')
            ->each(function ($pm) {
                $addon = json_decode($pm->addon_pricing, true);

                DB::table('platform_modules')
                    ->where('id', $pm->id)
                    ->update([
                        'pricing_mode' => 'addon',
                        'pricing_model' => $addon['pricing_model'] ?? null,
                        'pricing_metric' => $addon['pricing_metric'] ?? null,
                        'pricing_params' => json_encode($addon['pricing_params'] ?? null),
                    ]);
            });

        // 3. Drop addon_pricing
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn('addon_pricing');
        });
    }
};
