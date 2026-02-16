<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->json('default_roles')->nullable()->after('default_fields');
        });
    }

    public function down(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->dropColumn('default_roles');
        });
    }
};
