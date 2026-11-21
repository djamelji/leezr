<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-846 (Leezr 4.2 M8.1) — exceptional export initiated from Platform when the owner is unavailable : who asked
 * (operator), why, how the representative's authority was checked, who approved (a DIFFERENT operator), and the
 * company member the archive is delivered to. New statuses (string column) : partial · awaiting_approval · rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_data_exports', function (Blueprint $table) {
            $table->string('origin', 30)->default('owner')->after('requested_by_user_id'); // owner · platform_exceptional
            $table->foreignId('recipient_user_id')->nullable()->after('origin')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('requested_by_platform_user_id')->nullable()->after('recipient_user_id');
            $table->unsignedBigInteger('approved_by_platform_user_id')->nullable()->after('requested_by_platform_user_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by_platform_user_id');
            $table->text('justification')->nullable()->after('approved_at');
            $table->text('authority_check')->nullable()->after('justification');
        });
    }

    public function down(): void
    {
        Schema::table('company_data_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn(['origin', 'requested_by_platform_user_id', 'approved_by_platform_user_id', 'approved_at', 'justification', 'authority_check']);
        });
    }
};
