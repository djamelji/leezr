<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('market_key', 10)->nullable()->after('plan_key');
            $table->string('legal_status_key', 50)->nullable()->after('market_key');

            $table->foreign('market_key')->references('key')->on('markets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['market_key']);
            $table->dropColumn(['market_key', 'legal_status_key']);
        });
    }
};
