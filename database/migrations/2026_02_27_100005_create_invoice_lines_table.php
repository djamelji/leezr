<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // plan | addon | proration | adjustment
            $table->string('module_key', 50)->nullable();
            $table->string('description', 255);
            $table->smallInteger('quantity')->default(1);
            $table->bigInteger('unit_amount'); // cents per unit
            $table->bigInteger('amount'); // quantity × unit_amount
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
