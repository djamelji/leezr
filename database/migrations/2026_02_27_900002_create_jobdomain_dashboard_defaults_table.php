<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobdomain_dashboard_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobdomain_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('layout_json');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobdomain_dashboard_defaults');
    }
};
