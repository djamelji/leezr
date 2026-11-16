<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-803.1 — ADDITIVE. Fingerprint of the module's access at confirmation time : a later change of role
 * permissions OUTSIDE the assistant (or a deleted role) makes « ready » stale (computed at read, no sync job).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_module_setups', 'access_fingerprint')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->string('access_fingerprint', 40)->nullable()->after('revision'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_module_setups', 'access_fingerprint')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->dropColumn('access_fingerprint'));
        }
    }
};
