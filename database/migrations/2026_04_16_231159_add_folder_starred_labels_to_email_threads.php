<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_threads', function (Blueprint $table) {
            $table->string('folder', 20)->default('inbox')->after('status');
            $table->boolean('is_starred')->default(false)->after('folder');
            $table->json('labels')->nullable()->after('is_starred');

            $table->index(['folder', 'last_message_at'], 'idx_email_threads_folder_last');
        });
    }

    public function down(): void
    {
        Schema::table('email_threads', function (Blueprint $table) {
            $table->dropIndex('idx_email_threads_folder_last');
            $table->dropColumn(['folder', 'is_starred', 'labels']);
        });
    }
};
