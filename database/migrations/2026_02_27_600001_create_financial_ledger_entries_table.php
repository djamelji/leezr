<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('entry_type');     // invoice_issued | payment_received | refund_issued | writeoff | adjustment
            $table->string('account_code');   // AR | CASH | REVENUE | REFUND | BAD_DEBT
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('currency', 3);
            $table->string('reference_type'); // invoice | payment | credit_note
            $table->unsignedBigInteger('reference_id');
            $table->uuid('correlation_id')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger_entries');
    }
};
