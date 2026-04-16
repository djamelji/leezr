<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category'); // billing, documents, support, members
            $table->string('name');
            $table->string('subject_fr');
            $table->string('subject_en');
            $table->text('body_fr');
            $table->text('body_en');
            $table->json('variables');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->json('preview_data')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
