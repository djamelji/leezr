<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-803 — ADDITIVE. Guided-configuration state of a module for a company (to_configure → in_progress →
 * ready). No row = module activated before ADR-803 (« historical configuration »), never blocked.
 * Access choices themselves live in the existing RBAC (role permissions) ; this table only tracks progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_module_setups')) {
            return;
        }
        Schema::create('company_module_setups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('module_key', 64);
            $t->string('status', 20)->default('to_configure'); // to_configure | in_progress | ready
            $t->json('completed_steps')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamp('configured_at')->nullable();
            $t->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_setups');
    }
};
