<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'jobdomain_key')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('jobdomain_key', 50)->nullable()->after('market_key');
            });
        }

        // Backfill from existing pivot data — NO default, NO fallback
        if (Schema::hasTable('company_jobdomain')) {
            $pivotData = DB::table('company_jobdomain')
                ->join('jobdomains', 'jobdomains.id', '=', 'company_jobdomain.jobdomain_id')
                ->select('company_jobdomain.company_id', 'jobdomains.key')
                ->get();

            foreach ($pivotData as $row) {
                DB::table('companies')
                    ->where('id', $row->company_id)
                    ->update(['jobdomain_key' => $row->key]);
            }
        }

        // FAIL explicitly if any companies have no jobdomain after backfill
        // In production: this catches data integrity issues early
        // In testing (SQLite): table is empty, so count is 0 — passes safely
        $orphans = DB::table('companies')->whereNull('jobdomain_key')->count();
        if ($orphans > 0) {
            throw new \RuntimeException(
                "MIGRATION ABORTED: {$orphans} companies have no jobdomain. "
                . "Fix data manually before re-running: assign a jobdomain_key to each company."
            );
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('jobdomain_key');
        });
    }
};
