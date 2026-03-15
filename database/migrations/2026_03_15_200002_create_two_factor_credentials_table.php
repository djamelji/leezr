<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('authenticatable_type');
            $table->unsignedBigInteger('authenticatable_id');
            $table->text('secret'); // encrypted TOTP secret
            $table->text('backup_codes')->nullable(); // encrypted:array cast → base64 string, not JSON
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['authenticatable_type', 'authenticatable_id'], 'two_factor_auth_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_credentials');
    }
};
