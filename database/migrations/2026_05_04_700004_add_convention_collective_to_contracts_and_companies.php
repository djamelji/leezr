<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_employment_contracts', function (Blueprint $table) {
            $table->foreignId('convention_collective_id')
                ->nullable()
                ->after('company_id')
                ->constrained('convention_collectives')
                ->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('default_convention_collective_id')
                ->nullable()
                ->after('market_key')
                ->constrained('convention_collectives')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workforce_employment_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convention_collective_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_convention_collective_id');
        });
    }
};
