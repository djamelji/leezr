<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('market_key', 10);
            $table->string('locale', 10);
            $table->string('namespace', 100);
            $table->string('key', 200);
            $table->text('value');
            $table->timestamps();

            $table->unique(['market_key', 'locale', 'namespace', 'key'], 'translation_overrides_unique');
            $table->foreign('market_key')->references('key')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
