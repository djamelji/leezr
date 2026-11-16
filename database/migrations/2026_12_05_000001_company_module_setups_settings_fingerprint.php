<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-804 — ADDITIVE. Fingerprint of the module's EFFECTIVE mandatory settings at confirmation time. Replaces
 * the timestamp heuristic of ADR-803.1 (a write without effective change no longer makes « ready » stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_module_setups', 'settings_fingerprint')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->string('settings_fingerprint', 40)->nullable()->after('access_fingerprint'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_module_setups', 'settings_fingerprint')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->dropColumn('settings_fingerprint'));
        }
    }
};
