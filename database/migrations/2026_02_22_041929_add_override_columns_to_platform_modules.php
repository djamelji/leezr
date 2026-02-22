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
            $table->string('display_name_override')->nullable()->after('notes');
            $table->text('description_override')->nullable()->after('display_name_override');
            $table->string('min_plan_override')->nullable()->after('description_override');
            $table->integer('sort_order_override')->nullable()->after('min_plan_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn([
                'display_name_override',
                'description_override',
                'min_plan_override',
                'sort_order_override',
            ]);
        });
    }
};
