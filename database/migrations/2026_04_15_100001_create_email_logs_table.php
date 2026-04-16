<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique()->comment('UUID@domain — RFC 2822 compatible, for future threading');
            $table->string('external_id')->nullable()->comment('SMTP provider message ID for delivery tracking');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('from_email');
            $table->string('reply_to')->nullable();
            $table->string('subject');
            $table->string('template_key')->comment('e.g. billing.payment_failed');
            $table->string('notification_class')->comment('e.g. PaymentFailed');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error_message')->nullable();
            $table->json('headers')->nullable()->comment('SMTP headers for future threading (Message-ID, In-Reply-To, References)');
            $table->json('metadata')->nullable()->comment('Context: invoice_id, subscription_id, etc.');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('template_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
