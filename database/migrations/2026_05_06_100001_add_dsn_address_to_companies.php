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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_street', 500)->nullable()->after('naf_code');
            $table->string('address_complement', 200)->nullable()->after('address_street');
            $table->string('address_postal_code', 10)->nullable()->after('address_complement');
            $table->string('address_city', 100)->nullable()->after('address_postal_code');
            $table->string('address_insee_code', 5)->nullable()->after('address_city')->comment('Code commune INSEE pour DSN');
            $table->string('address_country_code', 2)->nullable()->default('FR')->after('address_insee_code');
            $table->smallInteger('average_headcount')->unsigned()->nullable()->after('address_country_code')->comment('Effectif moyen annuel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'address_street',
                'address_complement',
                'address_postal_code',
                'address_city',
                'address_insee_code',
                'address_country_code',
                'average_headcount',
            ]);
        });
    }
};
