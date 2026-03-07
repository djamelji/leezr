<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-220: Company addon subscriptions table.
 *
 * Tracks per-company addon module subscriptions.
 * One row per company/module combination. Reactivation = UPDATE deactivated_at = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_addon_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->string('interval', 10)->nullable(); // 'monthly'|'yearly'|null (derived from plan)
            $table->bigInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module_key']);
            $table->index('module_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_addon_subscriptions');
    }
};
