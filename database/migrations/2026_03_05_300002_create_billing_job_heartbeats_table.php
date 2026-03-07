<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_job_heartbeats', function (Blueprint $table) {
            $table->string('job_key', 80)->primary();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status', 20)->nullable(); // ok, failed
            $table->text('last_error')->nullable();
            $table->json('last_run_stats')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_job_heartbeats');
    }
};
