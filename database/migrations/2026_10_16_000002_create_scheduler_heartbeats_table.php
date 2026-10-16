<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row heartbeat proving the Laravel scheduler (cron schedule:run) is alive,
 * independent of business-task history. Lets globalHealth() distinguish "scheduler
 * stopped" from "no business task has run yet" (e.g. right after a staging reset).
 *
 * `installed_at` anchors a startup GRACE window: when no beat has arrived yet, the
 * external probe tolerates it only for a short time after the anchor, then reports
 * unhealthy. The singleton row is created lazily (first pulse or first probe) so the
 * anchor is ~deploy time without a seeded migration row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->timestamp('beat_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->string('environment', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
    }
};
