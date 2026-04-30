<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->unsignedInteger('api_requests')->default(0);
            $table->unsignedInteger('ai_requests')->default(0);
            $table->unsignedBigInteger('ai_tokens')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);
            $table->unsignedInteger('active_members')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
    }
};
