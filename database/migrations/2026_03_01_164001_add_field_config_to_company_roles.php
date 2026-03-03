<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_roles', function (Blueprint $table) {
            $table->json('field_config')->nullable()->after('is_administrative');
        });
    }

    public function down(): void
    {
        Schema::table('company_roles', function (Blueprint $table) {
            $table->dropColumn('field_config');
        });
    }
};
