<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic_key');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('icon')->nullable();
            $table->string('severity')->default('info');
            $table->string('link')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['recipient_type', 'recipient_id', 'company_id', 'read_at', 'created_at'],
                'notif_events_recipient_read_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
