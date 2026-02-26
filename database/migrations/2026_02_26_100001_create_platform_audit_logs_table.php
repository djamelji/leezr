<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-130: Platform-level audit log (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 20)->default('admin'); // admin, system, webhook
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 100)->nullable();
            $table->string('severity', 20)->default('info'); // info, warning, critical
            $table->json('diff_before')->nullable();
            $table->json('diff_after')->nullable();
            $table->ulid('correlation_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
