<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-849 (Leezr 4.2 M10) — public registration funnel : a random, client-generated journey id (no personal data)
 * replaces the Laravel session id as the key of a journey (the session is regenerated during the flow), and one row
 * per (journey, step) makes the tracking idempotent (a step sent twice is counted once). Additive : historical rows
 * keep their session_id and a NULL funnel_id (several NULLs are allowed by the unique index on MySQL / MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_funnel_events', function (Blueprint $table) {
            $table->string('funnel_id', 36)->nullable()->after('session_id');
            $table->unique(['funnel_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::table('registration_funnel_events', function (Blueprint $table) {
            $table->dropUnique(['funnel_id', 'step']);
            $table->dropColumn('funnel_id');
        });
    }
};
