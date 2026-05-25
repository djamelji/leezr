<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('metadata')->constrained('workforce_departments')->nullOnDelete();
            $table->foreignId('job_role_id')->nullable()->after('department_id')->constrained('workforce_job_roles')->nullOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable()->after('job_role_id');

            $table->foreign('manager_id')->references('id')->on('workforce_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workforce_employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['job_role_id']);
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['department_id', 'job_role_id', 'manager_id']);
        });
    }
};
