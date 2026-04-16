<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('direction')->default('sent')->after('status'); // sent, received
            $table->foreignId('thread_id')->nullable()->after('direction')->constrained('email_threads')->nullOnDelete();
            $table->boolean('is_read')->default(true)->after('thread_id');
            $table->text('body_html')->nullable()->after('subject');
            $table->text('body_text')->nullable()->after('body_html');
            $table->string('in_reply_to')->nullable()->after('message_id');

            $table->index('thread_id');
            $table->index('direction');
            $table->index('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropColumn(['direction', 'thread_id', 'is_read', 'body_html', 'body_text', 'in_reply_to']);
        });
    }
};
