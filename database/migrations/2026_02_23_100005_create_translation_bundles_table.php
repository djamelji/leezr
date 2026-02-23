<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10);
            $table->string('namespace', 100);
            $table->json('translations');
            $table->timestamps();

            $table->unique(['locale', 'namespace']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_bundles');
    }
};
