<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_orchestration_rules', function (Blueprint $table) {
            $table->string('frequency')->nullable()->after('timing');
        });
    }

    public function down(): void
    {
        Schema::table('email_orchestration_rules', function (Blueprint $table) {
            $table->dropColumn('frequency');
        });
    }
};
