<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-770 (M7 LOT 4) — traceability of the SUBJECT TRANSITION of personal documents and requests
 * (`employee:{id}` ⇄ `user:{id}`). Additive only.
 *
 *  - `origin_employee_id` : the HR record this row belongs to while it is keyed on the linked
 *    account. It is what lets the row RETURN to the HR record when the access is removed.
 *  - `previous_user_id`   : the account that held the row before it returned to the HR record. It
 *    is what prevents the row from being handed over to ANOTHER account linked later.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['member_documents', 'document_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'origin_employee_id')) {
                    $t->unsignedBigInteger('origin_employee_id')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'previous_user_id')) {
                    $t->unsignedBigInteger('previous_user_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['member_documents', 'document_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'origin_employee_id')) {
                    $t->dropIndex(['origin_employee_id']);
                    $t->dropColumn('origin_employee_id');
                }
                if (Schema::hasColumn($table, 'previous_user_id')) {
                    $t->dropColumn('previous_user_id');
                }
            });
        }
    }
};
