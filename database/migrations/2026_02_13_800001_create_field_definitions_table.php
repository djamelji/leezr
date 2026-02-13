<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('scope', ['platform_user', 'company', 'company_user']);
            $table->string('label');
            $table->enum('type', ['string', 'number', 'boolean', 'date', 'select', 'json']);
            $table->json('validation_rules')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('created_by_platform')->default(true);
            $table->unsignedInteger('default_order')->default(0);
            $table->timestamps();

            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }
};
