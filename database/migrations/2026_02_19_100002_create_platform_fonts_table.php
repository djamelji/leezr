<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fonts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')
                ->constrained('platform_font_families')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('weight');
            $table->enum('style', ['normal', 'italic']);
            $table->string('format')->default('woff2');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('sha256', 64);
            $table->timestamps();

            $table->unique(['family_id', 'weight', 'style']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fonts');
    }
};
