<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-796.1 — additive only.
 *  - `onboarding_uploads` : QUARANTINE of files deposited from the public portal (never a DMS document
 *    before a clean scan) ;
 *  - invitation : delivery of the invitation e-mail, one-time code limits, verification lock ;
 *  - workforce_employees.preparatory_at : marks the inactive records created for a recruit not yet integrated
 *    (targeted backfill of the records created by the ADR-796 onboarding flow only).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guards : MySQL DDL is not transactional (a partial first run must be resumable).
        if (! Schema::hasTable('onboarding_uploads')) Schema::create('onboarding_uploads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_invitation_id')->constrained('onboarding_invitations')->cascadeOnDelete();
            $t->string('document_code', 100);
            $t->json('files'); // [{path, name, size, mime, sha256}] on the private quarantine disk
            $t->string('status', 20)->default('quarantined'); // quarantined | ingested | infected | scan_failed | superseded
            $t->string('scan_engine', 40)->nullable();
            $t->string('scan_signature', 191)->nullable();
            $t->unsignedSmallInteger('scan_attempts')->default(0);
            $t->string('last_error', 255)->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->unsignedBigInteger('member_document_id')->nullable();
            $t->timestamp('files_deleted_at')->nullable();
            $t->timestamps();
            $t->index(['onboarding_invitation_id', 'document_code'], 'onb_up_inv_code_idx');
            $t->index(['status', 'updated_at'], 'onb_up_status_idx');
        });

        if (! Schema::hasColumn('onboarding_invitations', 'delivery_status')) Schema::table('onboarding_invitations', function (Blueprint $t) {
            $t->string('delivery_status', 20)->nullable()->after('sent_at'); // sent | failed
            $t->string('delivery_error', 255)->nullable()->after('delivery_status');
            $t->timestamp('mailed_at')->nullable()->after('delivery_error');
            $t->unsignedSmallInteger('mail_count')->default(0)->after('mailed_at');
            $t->timestamp('mail_window_at')->nullable()->after('mail_count');
            $t->unsignedSmallInteger('code_sends')->default(0)->after('code_attempts');
            $t->timestamp('code_window_at')->nullable()->after('code_sends');
            $t->unsignedSmallInteger('code_failures')->default(0)->after('code_window_at');
            $t->timestamp('verification_locked_at')->nullable()->after('code_failures');
            $t->timestamp('verified_at')->nullable()->after('verification_locked_at');
            $t->timestamp('expired_at')->nullable()->after('revoked_at');
        });

        if (! Schema::hasColumn('workforce_employees', 'preparatory_at')) Schema::table('workforce_employees', function (Blueprint $t) {
            $t->timestamp('preparatory_at')->nullable()->after('status');
        });

        // Targeted backfill : records created by the onboarding flow (same instant as their file, no
        // account, still inactive). Nothing else is touched.
        foreach (DB::table('onboarding_invitations')->get(['employee_id', 'created_at']) as $inv) {
            DB::table('workforce_employees')->where('id', $inv->employee_id)->where('status', 'inactive')->whereNull('user_id')
                ->whereBetween('created_at', [date('Y-m-d H:i:s', strtotime($inv->created_at) - 5), $inv->created_at])
                ->update(['preparatory_at' => $inv->created_at]);
        }
    }

    public function down(): void
    {
        Schema::table('workforce_employees', fn (Blueprint $t) => $t->dropColumn('preparatory_at'));
        Schema::table('onboarding_invitations', fn (Blueprint $t) => $t->dropColumn([
            'delivery_status', 'delivery_error', 'mailed_at', 'mail_count', 'mail_window_at', 'code_sends', 'code_window_at',
            'code_failures', 'verification_locked_at', 'verified_at', 'expired_at',
        ]));
        Schema::dropIfExists('onboarding_uploads');
    }
};
