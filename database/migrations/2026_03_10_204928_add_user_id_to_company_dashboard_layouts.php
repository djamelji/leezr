<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-326: Per-user dashboard layouts for company surface.
 *
 * Adds user_id column so each member gets their own layout.
 * Existing rows (user_id=NULL) become company-wide defaults
 * used as fallback for users who haven't customized yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_dashboard_layouts', function (Blueprint $table) {
            if (!Schema::hasColumn('company_dashboard_layouts', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('company_id');
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        // Replace unique(company_id) with unique(company_id, user_id)
        // SQLite doesn't support ALTER INDEX so we handle it cleanly
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: indexes from original migration won't conflict in :memory: test DB
            // Just add the composite unique if it doesn't already exist
            try {
                Schema::table('company_dashboard_layouts', function (Blueprint $table) {
                    $table->unique(['company_id', 'user_id'], 'cdl_company_user_unique');
                });
            } catch (\Throwable) {
                // Index already exists — skip
            }
        } else {
            // MySQL: drop FK → drop old unique → re-add FK → add composite unique
            try {
                Schema::table('company_dashboard_layouts', function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                    $table->dropUnique(['company_id']);
                    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                });
            } catch (\Throwable) {
                // Old unique already dropped — skip
            }

            try {
                Schema::table('company_dashboard_layouts', function (Blueprint $table) {
                    $table->unique(['company_id', 'user_id'], 'cdl_company_user_unique');
                });
            } catch (\Throwable) {
                // Composite unique already exists — skip
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('company_dashboard_layouts', function (Blueprint $table) use ($driver) {
            $table->dropUnique('cdl_company_user_unique');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            if ($driver !== 'sqlite') {
                $table->dropForeign(['company_id']);
                $table->unique('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            }
        });
    }
};
