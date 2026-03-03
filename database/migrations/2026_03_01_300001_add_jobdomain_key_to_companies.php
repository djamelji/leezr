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

        // Auto-fix orphans: assign first active jobdomain as fallback
        $orphans = DB::table('companies')->whereNull('jobdomain_key')->count();
        if ($orphans > 0) {
            $fallbackKey = DB::table('jobdomains')
                ->where('is_active', true)
                ->orderBy('id')
                ->value('key');

            if ($fallbackKey) {
                DB::table('companies')
                    ->whereNull('jobdomain_key')
                    ->update(['jobdomain_key' => $fallbackKey]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('jobdomain_key');
        });
    }
};
