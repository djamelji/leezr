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
        // Safety check: abort if any 'admin' rows still exist
        $adminCount = DB::table('memberships')->where('role', 'admin')->count();

        if ($adminCount > 0) {
            throw new RuntimeException(
                "Cannot remove 'admin' enum value: {$adminCount} membership(s) still have role='admin'. "
                . "Migrate them to role='user' first."
            );
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
