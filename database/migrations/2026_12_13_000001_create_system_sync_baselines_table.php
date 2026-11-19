<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-832 — provenance of administered catalog values. One row per (catalog, record, field) remembers the CODE
 * value last applied by the system sync. The sync then knows whether a value still equals what the code wrote
 * (safe to evolve) or was changed since in Platform (preserved, reported as a conflict). Values only, never a
 * second configuration: the catalog tables stay the runtime source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_sync_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 64);
            $table->string('subject_key', 191);
            $table->string('field', 128);
            $table->char('value_hash', 64);
            $table->longText('value')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->unique(['subject', 'subject_key', 'field'], 'system_sync_baselines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_sync_baselines');
    }
};
