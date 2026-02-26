<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-130: Company-scoped audit log (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 20)->default('user'); // user, admin, system, webhook
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

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'action', 'created_at']);
            $table->index(['company_id', 'actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_audit_logs');
    }
};
