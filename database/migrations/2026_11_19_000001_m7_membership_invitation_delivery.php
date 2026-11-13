<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 LOT 3 — invitation delivery state + expiry (additive).
 *  - delivery : queued | sent | failed, with the time of the last successful send and the last error ;
 *  - expiry   : deadline of a pending acceptance (config `members.invitation_ttl_days`) and the
 *               moment its expiry was traced in the audit log (idempotence of the scheduled task).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('invitation_delivery_status', 16)->nullable()->after('invited_by');
            $table->timestamp('invitation_sent_at')->nullable()->after('invitation_delivery_status');
            $table->string('invitation_delivery_error', 255)->nullable()->after('invitation_sent_at');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_delivery_error');
            $table->timestamp('invitation_expired_at')->nullable()->after('invitation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['invitation_delivery_status', 'invitation_sent_at', 'invitation_delivery_error', 'invitation_expires_at', 'invitation_expired_at']);
        });
    }
};
