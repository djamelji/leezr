<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_role_permission', function (Blueprint $table) {
            $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->foreignId('platform_permission_id')->constrained('platform_permissions')->cascadeOnDelete();
            $table->unique(['platform_role_id', 'platform_permission_id'], 'prp_role_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_permission');
    }
};
