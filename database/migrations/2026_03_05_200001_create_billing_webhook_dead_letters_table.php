<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key', 30);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamp('failed_at');
            $table->string('status', 20)->default('pending'); // pending, replayed, discarded
            $table->timestamp('replayed_at')->nullable();
            $table->unsignedInteger('replay_attempts')->default(0);
            $table->timestamps();

            $table->unique(['provider_key', 'event_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_dead_letters');
    }
};
