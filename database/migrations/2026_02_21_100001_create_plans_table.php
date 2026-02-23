<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('level')->default(0);
            $table->integer('price_monthly')->default(0); // cents
            $table->integer('price_yearly')->default(0);  // cents
            $table->boolean('is_popular')->default(false);
            $table->json('feature_labels')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
