<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_orchestration_rules', function (Blueprint $table) {
            $table->id();
            $table->string('template_key');
            $table->string('trigger_event'); // e.g. 'trial.expiring', 'payment.failed'
            $table->string('timing')->default('immediate'); // immediate, delayed
            $table->unsignedInteger('delay_value')->default(0);
            $table->string('delay_unit')->default('days'); // days, hours, minutes
            $table->json('conditions')->nullable(); // JSON conditions
            $table->unsignedInteger('max_sends')->default(1); // per entity per event
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('trigger_event');
            $table->index('is_active');
            $table->foreign('template_key')->references('key')->on('email_templates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_orchestration_rules');
    }
};
