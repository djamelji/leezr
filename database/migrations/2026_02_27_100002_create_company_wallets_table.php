<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->bigInteger('cached_balance')->default(0); // cents — source of truth is transactions
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_wallets');
    }
};
