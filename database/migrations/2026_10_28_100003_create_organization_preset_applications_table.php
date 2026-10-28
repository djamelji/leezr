<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-636 (W-E) — traçabilité des applications de preset par Company (provenance + version appliquée +
 * résumé). Sert aussi d'ancrage d'idempotence/versionnement. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_preset_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('preset_key', 80);
            $table->unsignedInteger('preset_version');
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->json('summary'); // {created:[], skipped:[], conflicts:[]}
            $table->timestamps();

            $table->index(['company_id', 'preset_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_preset_applications');
    }
};
