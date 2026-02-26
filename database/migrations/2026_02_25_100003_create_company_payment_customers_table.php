<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 30);
            $table->string('provider_customer_id', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_customers');
    }
};
