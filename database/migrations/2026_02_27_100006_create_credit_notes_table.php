<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount'); // cents (positive = credit)
            $table->string('currency', 3)->default('EUR');
            $table->string('reason', 255);
            $table->string('status', 20); // draft | issued | applied
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->json('billing_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('invoice_id');

            $table->foreign('wallet_transaction_id')
                ->references('id')
                ->on('company_wallet_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
