<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobdomain_market_overlays', function (Blueprint $table) {
            $table->id();
            $table->string('jobdomain_key', 50);
            $table->string('market_key', 10);

            // Override: partial additions/replacements merged onto global defaults
            $table->json('override_modules')->nullable();
            $table->json('override_fields')->nullable();
            $table->json('override_documents')->nullable();
            $table->json('override_roles')->nullable();

            // Remove: items to exclude from global defaults
            $table->json('remove_modules')->nullable();
            $table->json('remove_fields')->nullable();
            $table->json('remove_documents')->nullable();
            $table->json('remove_roles')->nullable();

            $table->timestamps();

            $table->unique(['jobdomain_key', 'market_key']);

            $table->foreign('jobdomain_key')
                ->references('key')->on('jobdomains')
                ->cascadeOnDelete();

            $table->foreign('market_key')
                ->references('key')->on('markets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobdomain_market_overlays');
    }
};
