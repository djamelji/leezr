<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove 'admin' from memberships.role enum.
 *
 * The CompanyRole system (is_administrative flag) is now the sole source
 * of truth for administrative access. The legacy 'admin' enum value is
 * no longer used and is removed to prevent confusion.
 *
 * Preconditions (enforced in up):
 *   - No membership rows have role='admin'
 *   - All role='admin' rows must be migrated to 'user' before running
 */
return new class extends Migration
{
    public function up(): void
    {
        // Auto-migrate any remaining 'admin' rows to 'user' before removing the enum value.
        // The CompanyRole system (is_administrative flag) is now the sole source of truth.
        $migrated = DB::table('memberships')->where('role', 'admin')->update(['role' => 'user']);

        if ($migrated > 0) {
            logger()->info("[migration] Migrated {$migrated} membership(s) from role='admin' to role='user'.");
        }

        // SQLite has no ENUM type — constraint is purely MySQL-side
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE memberships MODIFY COLUMN role ENUM('owner', 'user') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE memberships MODIFY COLUMN role ENUM('owner', 'admin', 'user') NOT NULL");
        }
    }
};
