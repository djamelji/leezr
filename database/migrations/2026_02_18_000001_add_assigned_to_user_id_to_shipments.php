<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['company_id', 'assigned_to_user_id', 'status'], 'shipments_company_assigned_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropIndex('shipments_company_assigned_status_index');
            $table->dropColumn('assigned_to_user_id');
        });
    }
};
