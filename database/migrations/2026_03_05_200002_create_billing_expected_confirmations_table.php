<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_expected_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 30);
            $table->string('expected_event_type', 100);
            $table->string('provider_reference', 255)->nullable(); // e.g. checkout session ID, payment intent ID, setup intent ID
            $table->string('status', 20)->default('pending'); // pending, confirmed, recovered, expired
            $table->timestamp('expected_by')->nullable(); // after this, trigger recovery
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expected_by']);
            $table->index(['provider_key', 'provider_reference'], 'bec_provider_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_expected_confirmations');
    }
};
