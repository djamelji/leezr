<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('market_key', 10);
            $table->string('key', 50);
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['market_key', 'key']);
            $table->foreign('market_key')->references('key')->on('markets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_statuses');
    }
};
