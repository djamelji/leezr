<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 30);
            $table->string('method_key', 50);
            $table->string('provider_payment_method_id', 255)->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'provider_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_profiles');
    }
};
