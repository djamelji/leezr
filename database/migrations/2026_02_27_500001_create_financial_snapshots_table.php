<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');       // auto_repair | forensics
            $table->string('drift_type');    // missing_local_payment | status_mismatch | invoice_not_paid
            $table->string('entity_type');   // payment | invoice
            $table->string('entity_id');
            $table->json('snapshot_data');
            $table->string('correlation_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['company_id', 'entity_type', 'entity_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_snapshots');
    }
};
