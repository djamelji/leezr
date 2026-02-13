<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->boolean('allow_custom_fields')->default(false)->after('default_fields');
        });
    }

    public function down(): void
    {
        Schema::table('jobdomains', function (Blueprint $table) {
            $table->dropColumn('allow_custom_fields');
        });
    }
};
