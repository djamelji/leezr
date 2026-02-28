<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_user_dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('platform_users')->cascadeOnDelete();
            $table->json('layout_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_dashboard_layouts');
    }
};
