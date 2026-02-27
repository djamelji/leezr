<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_permissions', function (Blueprint $table) {
            $table->string('module_key', 50)->nullable()->after('label');
            $table->boolean('is_admin')->default(false)->after('module_key');
            $table->index('module_key');
        });
    }

    public function down(): void
    {
        Schema::table('platform_permissions', function (Blueprint $table) {
            $table->dropIndex(['module_key']);
            $table->dropColumn(['module_key', 'is_admin']);
        });
    }
};
