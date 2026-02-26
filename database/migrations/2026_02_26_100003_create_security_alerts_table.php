<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('alert_type');
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('evidence');
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'false_positive'])->default('open');
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['status', 'severity', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['alert_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
