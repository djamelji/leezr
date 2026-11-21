<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-845 (Leezr 4.2 M8) — full data export of a company (end of relationship). One row per request ; the archive
 * lives on the private disk until `expires_at`, then the FILE is pruned (the row stays, status `expired`, as trace).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_data_exports')) {
            return;
        }
        Schema::create('company_data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending · running · ready · failed · expired · revoked
            $table->string('disk', 40)->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_data_exports');
    }
};
