<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 LOT 2 — explicit acceptance of a company invitation by a PRE-EXISTING account (additive).
 * A pending membership also carries `suspended_at`, so every existing access check keeps it out
 * until the recipient accepts; `acceptance_pending_at` distinguishes it from an admin suspension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->timestamp('acceptance_pending_at')->nullable()->after('suspension_reason');
            $table->foreignId('invited_by')->nullable()->after('acceptance_pending_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('acceptance_pending_at');
        });
    }
};
