<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_plan_key', 30);
            $table->string('to_plan_key', 30);
            $table->string('interval_from', 10); // monthly | yearly
            $table->string('interval_to', 10);   // monthly | yearly
            $table->string('timing', 20);         // immediate | end_of_period | end_of_trial
            $table->timestamp('effective_at')->nullable();
            $table->json('proration_snapshot')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled | executed | cancelled
            $table->timestamp('executed_at')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_intents');
    }
};
