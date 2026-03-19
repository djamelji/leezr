<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean orphaned references before adding FKs
        foreach (['actor_id', 'acknowledged_by', 'resolved_by'] as $column) {
            DB::table('security_alerts')
                ->whereNotNull($column)
                ->whereNotExists(function ($query) use ($column) {
                    $query->select(DB::raw(1))
                        ->from('platform_users')
                        ->whereColumn('platform_users.id', "security_alerts.{$column}");
                })
                ->update([$column => null]);
        }

        Schema::table('security_alerts', function (Blueprint $table) {
            $table->foreign('actor_id')
                ->references('id')->on('platform_users')
                ->nullOnDelete();

            $table->foreign('acknowledged_by')
                ->references('id')->on('platform_users')
                ->nullOnDelete();

            $table->foreign('resolved_by')
                ->references('id')->on('platform_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->dropForeign(['acknowledged_by']);
            $table->dropForeign(['resolved_by']);
        });
    }
};
