<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-808 — ADDITIVE. Permissions that roles ALREADY held when a module was activated for the first time
 * (dormant rights) or that a versioned creation pre-configured on operational roles : {role_id: [keys]}.
 * They stay stored on the role (no pivot change) but are not effective until the module's access step is
 * confirmed. NULL = nothing pending (every historical row).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_module_setups', 'pending_access')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->json('pending_access')->nullable()->after('settings_fingerprint'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_module_setups', 'pending_access')) {
            Schema::table('company_module_setups', fn (Blueprint $t) => $t->dropColumn('pending_access'));
        }
    }
};
