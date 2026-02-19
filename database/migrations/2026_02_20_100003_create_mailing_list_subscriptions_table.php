<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailing_list_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('mailing_lists')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'unsubscribed'])->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_list_subscriptions');
    }
};
