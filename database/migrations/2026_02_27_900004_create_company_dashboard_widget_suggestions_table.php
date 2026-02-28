<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_dashboard_widget_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('widget_key');
            $table->enum('status', ['pending', 'accepted', 'dismissed'])->default('pending');
            $table->timestamps();

            $table->unique(['company_id', 'widget_key'], 'cdws_company_widget_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_dashboard_widget_suggestions');
    }
};
