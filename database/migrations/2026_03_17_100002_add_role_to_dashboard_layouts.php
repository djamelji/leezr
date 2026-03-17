<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-357: Add company_role_id to dashboard layouts for role-specific defaults.
 *
 * Layout resolution cascade: user-specific → role-specific → company default → smart builder.
 *
 * Also replaces the composite unique (company_id, user_id) with
 * (company_id, user_id, company_role_id) to allow multiple role-specific defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_dashboard_layouts', function (Blueprint $table) {
            $table->foreignId('company_role_id')
                ->nullable()
                ->after('user_id')
                ->constrained('company_roles')
                ->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        // Drop old unique constraints before adding the new composite one
        if ($driver === 'sqlite') {
            // SQLite: drop any unique index that covers only (company_id) or (company_id, user_id)
            $indexes = DB::select("PRAGMA index_list('company_dashboard_layouts')");
            foreach ($indexes as $index) {
                if (!$index->unique) {
                    continue;
                }
                $cols = DB::select("PRAGMA index_info('{$index->name}')");
                $colNames = array_map(fn ($c) => $c->name, $cols);

                // Drop single-column company_id unique (from original migration)
                if ($colNames === ['company_id']) {
                    DB::statement("DROP INDEX \"{$index->name}\"");
                }
                // Drop composite (company_id, user_id) unique (from add_user_id migration)
                if ($colNames === ['company_id', 'user_id']) {
                    DB::statement("DROP INDEX \"{$index->name}\"");
                }
            }
        } else {
            // MySQL: drop named indexes
            try {
                Schema::table('company_dashboard_layouts', function (Blueprint $table) {
                    $table->dropUnique('cdl_company_user_unique');
                });
            } catch (\Throwable) {
                // Already dropped or doesn't exist
            }
        }

        // Add new composite unique that includes company_role_id
        Schema::table('company_dashboard_layouts', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'user_id', 'company_role_id'],
                'cdl_company_user_role_unique',
            );
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('company_dashboard_layouts', function (Blueprint $table) {
            $table->dropUnique('cdl_company_user_role_unique');
        });

        // Restore old composite unique
        try {
            Schema::table('company_dashboard_layouts', function (Blueprint $table) {
                $table->unique(['company_id', 'user_id'], 'cdl_company_user_unique');
            });
        } catch (\Throwable) {
            // Skip if already exists
        }

        Schema::table('company_dashboard_layouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_role_id');
        });
    }
};
