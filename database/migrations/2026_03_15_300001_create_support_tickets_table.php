<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->string('subject');
            $table->string('status')->default('open'); // open, in_progress, waiting_customer, resolved, closed
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->string('category')->nullable(); // billing, technical, general
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'priority']);
            $table->index('assigned_to_platform_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
