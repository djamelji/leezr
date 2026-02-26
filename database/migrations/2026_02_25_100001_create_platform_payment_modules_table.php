<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_modules', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_installed')->default(false);
            $table->boolean('is_active')->default(false);
            $table->text('credentials')->nullable(); // encrypted via model cast
            $table->string('health_status', 20)->default('unknown');
            $table->timestamp('health_checked_at')->nullable();
            $table->json('config')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_modules');
    }
};
