<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('layout_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_dashboard_layouts');
    }
};
