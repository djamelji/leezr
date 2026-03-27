<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ai_modules', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_installed')->default(false);
            $table->boolean('is_active')->default(false);
            $table->text('credentials')->nullable(); // encrypted:array
            $table->json('config')->nullable();
            $table->string('health_status')->nullable(); // healthy, degraded, down
            $table->timestamp('health_checked_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ai_modules');
    }
};
