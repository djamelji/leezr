<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('default_order');
            $table->json('visibility_roles')->nullable()->after('encrypted');
            $table->boolean('recommended')->default(false)->after('visibility_roles');
        });
    }

    public function down(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->dropColumn(['encrypted', 'visibility_roles', 'recommended']);
        });
    }
};
