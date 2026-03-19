<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-375: Preset reconciliation engine — snapshot table.
 * Stores a snapshot of the preset state at registration or reconciliation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_preset_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('jobdomain_key', 50);
            $table->string('trigger', 30); // 'registration', 'reconcile_apply', 'reconcile_dry_run'
            $table->json('roles_snapshot'); // full dump of roles + permissions at this point
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_preset_snapshots');
    }
};
