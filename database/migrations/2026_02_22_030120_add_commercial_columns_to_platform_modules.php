<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->boolean('is_listed')->default(false)->after('sort_order');
            $table->boolean('is_sellable')->default(false)->after('is_listed');
            $table->string('pricing_model')->nullable()->after('is_sellable');
            $table->string('pricing_metric')->nullable()->after('pricing_model');
            $table->json('pricing_params')->nullable()->after('pricing_metric');
            $table->json('settings_schema')->nullable()->after('pricing_params');
            $table->text('notes')->nullable()->after('settings_schema');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn([
                'is_listed',
                'is_sellable',
                'pricing_model',
                'pricing_metric',
                'pricing_params',
                'settings_schema',
                'notes',
            ]);
        });
    }
};
