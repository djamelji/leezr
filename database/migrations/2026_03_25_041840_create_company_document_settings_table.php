<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_document_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('auto_renew_enabled')->default(false);
            $table->unsignedSmallInteger('renew_days_before')->default(30);
            $table->boolean('auto_remind_enabled')->default(false);
            $table->unsignedSmallInteger('remind_after_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_settings');
    }
};
