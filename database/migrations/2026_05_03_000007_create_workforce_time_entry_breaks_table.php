<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_time_entry_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_entry_id')->constrained('workforce_time_entries')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->string('type', 20)->default('rest'); // lunch, rest, personal
            $table->integer('duration_minutes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'time_entry_id']);
            $table->index('time_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_time_entry_breaks');
    }
};
