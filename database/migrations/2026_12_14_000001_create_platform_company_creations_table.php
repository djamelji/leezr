<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-834 — one row per company created from Platform : idempotency (a double submission returns the same
 * company, never a second one), who created it, and the decisions taken in the wizard (resumable onboarding).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_company_creations')) {
            return;
        }
        Schema::create('platform_company_creations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('company_id')->nullable()->unique()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->json('decisions')->nullable();
            $table->json('initialization')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_company_creations');
    }
};
