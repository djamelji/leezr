<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6.8 — ADR-526: DSN submission lock table (anti-double submit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_dsn_submission_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('declaration_id')->unique();
            $table->timestamp('locked_at');
            $table->timestamp('expires_at');

            $table->foreign('declaration_id')
                ->references('id')
                ->on('workforce_dsn_declarations')
                ->cascadeOnDelete();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_dsn_submission_locks');
    }
};
