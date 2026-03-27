<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');             // ollama, null, ...
            $table->string('model');                // glm-ocr, qwen2.5-vl, ...
            $table->string('capability');           // vision, completion, text_extraction
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->string('status')->default('success'); // success, error
            $table->text('error_message')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module_key')->nullable(); // documents, support, ...
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['module_key', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_logs');
    }
};
