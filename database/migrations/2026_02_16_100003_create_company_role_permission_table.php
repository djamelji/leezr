<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_role_permission', function (Blueprint $table) {
            $table->foreignId('company_role_id')->constrained('company_roles')->cascadeOnDelete();
            $table->foreignId('company_permission_id')->constrained('company_permissions')->cascadeOnDelete();

            $table->unique(['company_role_id', 'company_permission_id'], 'crp_role_perm_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_role_permission');
    }
};
