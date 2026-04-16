<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-438: Platform Alert Center — centralized cross-system alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);          // billing, security, ai, infra, support, business
            $table->string('type', 100);            // invoice_overdue_7d, payment_failed_3x, etc.
            $table->string('severity', 20);         // critical, warning, info
            $table->string('status', 20)->default('active'); // active, acknowledged, resolved, dismissed
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();   // flexible data (invoice_id, provider_name, etc.)
            $table->string('target_type', 100)->nullable(); // App\Core\Billing\Invoice, etc.
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('fingerprint', 64)->unique(); // dedup: hash(source+type+target_type+target_id)
            $table->foreignId('acknowledged_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['source', 'status']);
            $table->index('company_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_alerts');
    }
};
