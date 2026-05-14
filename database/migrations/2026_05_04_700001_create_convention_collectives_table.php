<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convention_collectives', function (Blueprint $table) {
            $table->id();
            $table->string('idcc', 10);
            $table->string('short_name', 100);
            $table->string('full_name', 500);
            $table->string('market_key', 10)->default('FR');
            $table->string('brochure_number', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['idcc', 'market_key']);
            $table->foreign('market_key')->references('key')->on('markets')->cascadeOnDelete();
            $table->index(['market_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convention_collectives');
    }
};
