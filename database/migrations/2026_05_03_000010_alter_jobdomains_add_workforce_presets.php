<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->json('workforce_presets')->nullable()->after('default_roles');
        });
    }

    public function down(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->dropColumn('workforce_presets');
        });
    }
};
