<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce: non-owner memberships MUST have a CompanyRole.
 *
 * MySQL: BEFORE INSERT/UPDATE triggers (CHECK constraint cannot be used
 *        on columns involved in FK referential actions like ON DELETE SET NULL).
 * SQLite: No trigger needed — runtime middleware enforces the invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared("
            CREATE TRIGGER trg_membership_require_role_insert
            BEFORE INSERT ON memberships
            FOR EACH ROW
            BEGIN
                IF NEW.role != 'owner' AND NEW.company_role_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Non-owner membership must have a company_role_id';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_membership_require_role_update
            BEFORE UPDATE ON memberships
            FOR EACH ROW
            BEGIN
                IF NEW.role != 'owner' AND NEW.company_role_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Non-owner membership must have a company_role_id';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_membership_require_role_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_membership_require_role_update');
    }
};
