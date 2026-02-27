<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('company_wallets')->cascadeOnDelete();
            $table->string('type', 10); // credit | debit
            $table->bigInteger('amount'); // always positive, sign determined by type
            $table->bigInteger('balance_after'); // running balance after this transaction
            $table->string('source_type', 30); // credit_note, admin_adjustment, invoice_payment, refund, proration_credit
            $table->unsignedBigInteger('source_id')->nullable(); // polymorphic FK
            $table->string('description', 255)->nullable();
            $table->string('actor_type', 20)->nullable(); // user | system | admin
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable(); // immutable — no updated_at

            $table->index(['wallet_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_wallet_transactions');
    }
};
