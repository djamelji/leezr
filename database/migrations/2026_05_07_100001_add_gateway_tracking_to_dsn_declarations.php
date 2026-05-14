<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7.1 — ADR-529
 *
 * Add gateway tracking fields to workforce_dsn_declarations
 * for Net-Entreprises integration (Phase 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_dsn_declarations', function (Blueprint $table) {
            $table->string('gateway_driver', 20)->nullable()->after('submitted_at');
            $table->string('gateway_environment', 20)->nullable()->after('gateway_driver');
            $table->string('technical_receipt_path')->nullable()->after('gateway_environment');
            $table->string('business_report_path')->nullable()->after('technical_receipt_path');
            $table->string('gateway_status', 30)->nullable()->after('business_report_path');
            $table->string('gateway_error_code', 50)->nullable()->after('gateway_status');
            $table->text('gateway_error_message')->nullable()->after('gateway_error_code');
            $table->json('gateway_metadata')->nullable()->after('gateway_error_message');
            $table->timestamp('last_polled_at')->nullable()->after('gateway_metadata');
            $table->timestamp('next_poll_at')->nullable()->after('last_polled_at');
            $table->unsignedInteger('attempt_count')->default(0)->after('next_poll_at');

            $table->index('gateway_status');
            $table->index('next_poll_at');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_dsn_declarations', function (Blueprint $table) {
            $table->dropIndex(['gateway_status']);
            $table->dropIndex(['next_poll_at']);

            $table->dropColumn([
                'gateway_driver',
                'gateway_environment',
                'technical_receipt_path',
                'business_report_path',
                'gateway_status',
                'gateway_error_code',
                'gateway_error_message',
                'gateway_metadata',
                'last_polled_at',
                'next_poll_at',
                'attempt_count',
            ]);
        });
    }
};
