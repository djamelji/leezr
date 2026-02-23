<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_language', function (Blueprint $table) {
            $table->string('market_key', 10);
            $table->string('language_key', 10);
            $table->timestamps();

            $table->primary(['market_key', 'language_key']);
            $table->foreign('market_key')->references('key')->on('markets')->cascadeOnDelete();
            $table->foreign('language_key')->references('key')->on('languages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_language');
    }
};
