<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted enable/disable state for scheduled tasks (automations cockpit).
 * Absence of a row = enabled (default). A disabled task is really skipped by the
 * scheduler (->when filter) and refused by manual run — not a cosmetic flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_states', function (Blueprint $table) {
            $table->id();
            $table->string('task', 100)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->string('disabled_by', 100)->nullable();
            $table->string('disabled_reason', 500)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_states');
    }
};
