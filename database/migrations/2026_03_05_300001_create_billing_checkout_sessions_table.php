<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 30)->default('stripe');
            $table->string('provider_session_id', 255);
            $table->string('status', 20)->default('created'); // created, completed, expired, cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_key', 'provider_session_id'], 'bcs_provider_session_unique');
            $table->index(['company_id', 'status']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_checkout_sessions');
    }
};
