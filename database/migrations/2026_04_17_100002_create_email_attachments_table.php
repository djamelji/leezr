<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_log_id')->nullable();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->timestamp('created_at')->nullable();

            $table->index('email_log_id', 'idx_email_attachments_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
