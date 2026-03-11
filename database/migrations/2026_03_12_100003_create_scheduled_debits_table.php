<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-328 LOT I S2: Scheduled SEPA debits for deferred payment collection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_debits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_profile_id')
                ->nullable()
                ->constrained('company_payment_profiles')
                ->nullOnDelete();
            $table->integer('amount');
            $table->string('currency', 3)->default('EUR');
            $table->date('debit_date');
            $table->string('status', 20)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['debit_date', 'status']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_debits');
    }
};
