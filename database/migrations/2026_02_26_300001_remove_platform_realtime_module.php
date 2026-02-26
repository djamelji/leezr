<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-131: Merge Realtime into Security & Monitoring.
 *
 * The platform.realtime module is absorbed by platform.security.
 * Remove the module row so it no longer appears in the module catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_modules')->where('key', 'platform.realtime')->delete();
    }

    public function down(): void
    {
        // Re-inserting the module row is not necessary — the module definition
        // class no longer exists, so re-adding the row would be incoherent.
    }
};
