<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-847 (Leezr 4.2 M8.2, R-M81-2) — `actor_type` is an actor CATEGORY (admin · system · webhook, varchar 20) but the
 * logger wrote the operator's first ROLE KEY into it (platform_roles.key : varchar 255, validated ≤ 50) : a role key
 * longer than 20 characters made every audited action of its holders — login included — fail with a 500 on MySQL /
 * MariaDB. The role gets its own column, as wide as its source. Additive : nothing dropped, no existing value changed ;
 * historical rows whose actor_type holds a role key get that key COPIED into actor_role (actor_type left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->string('actor_role', 255)->nullable()->after('actor_type');
        });

        DB::table('platform_audit_logs')->whereNotIn('actor_type', ['admin', 'system', 'webhook'])->whereNull('actor_role')
            ->update(['actor_role' => DB::raw('actor_type')]);
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_role');
        });
    }
};
