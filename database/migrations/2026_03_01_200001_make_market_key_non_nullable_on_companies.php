<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-165: Market Hardening — backfill market_key for existing companies.
 *
 * Column stays nullable (FK compat with SQLite in tests),
 * but code guarantees it's always populated (CompanyFactory, AuthController).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill any existing NULL rows to FR (production/dev safety)
        DB::table('companies')->whereNull('market_key')->update(['market_key' => 'FR']);
    }

    public function down(): void
    {
        // No-op: making rows NULL again would be destructive
    }
};
