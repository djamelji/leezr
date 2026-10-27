<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M1) — lease de réservation, ADDITIVE.
 *
 * `reserved_at` matérialise le début du lease d'une réservation par un BillingRun. `releaseStale`
 * ne libère QUE les réservations dont le lease a expiré (reserved_at < now − TTL) : un run vivant
 * doit se terminer ou renouveler son lease dans le TTL (le renouvellement de lease relève de M2 /
 * BillingRun). Ainsi une réservation active n'est jamais libérée parce qu'elle est « ancienne ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billable_events', function (Blueprint $table) {
            $table->dateTime('reserved_at')->nullable()->after('reserved_by_run_id');
            $table->index(['status', 'reserved_at']); // balayage efficace des leases expirés
        });
    }

    public function down(): void
    {
        Schema::table('billable_events', function (Blueprint $table) {
            $table->dropIndex(['status', 'reserved_at']);
            $table->dropColumn('reserved_at');
        });
    }
};
