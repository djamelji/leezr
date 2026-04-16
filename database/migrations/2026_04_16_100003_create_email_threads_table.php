<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('participant_email');
            $table->string('participant_name')->nullable();
            $table->string('status')->default('open'); // open, closed, archived
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index('company_id');
            $table->index('participant_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
