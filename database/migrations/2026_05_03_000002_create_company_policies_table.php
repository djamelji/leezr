<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 50);
            $table->string('policy_key', 100);
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('source', 50); // jobdomain_preset, admin_manual, contract_sync, platform_default
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'domain', 'policy_key', 'effective_from'], 'cp_unique');
            $table->index(['company_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_policies');
    }
};
