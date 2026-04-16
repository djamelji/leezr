<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-437: Company-scoped workflow rules + execution logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger_topic');
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_executions_per_day')->default(100);
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->unsignedInteger('executions_today')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'trigger_topic', 'enabled']);
        });

        Schema::create('workflow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('trigger_topic');
            $table->json('trigger_payload')->nullable();
            $table->boolean('conditions_met')->default(false);
            $table->json('actions_executed')->nullable();
            $table->string('status'); // success, partial, skipped, failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['workflow_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_execution_logs');
        Schema::dropIfExists('workflow_rules');
    }
};
