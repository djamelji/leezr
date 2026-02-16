<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_permissions', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('module_key');
        });

        Schema::table('company_roles', function (Blueprint $table) {
            $table->boolean('is_administrative')->default(false)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('company_permissions', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::table('company_roles', function (Blueprint $table) {
            $table->dropColumn('is_administrative');
        });
    }
};
