<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_funnel_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('step', 50); // 'started', 'company_info', 'admin_user', 'plan_selected', 'payment_info', 'completed'
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['step', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_funnel_events');
    }
};
