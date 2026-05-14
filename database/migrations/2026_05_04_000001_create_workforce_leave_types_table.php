<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('accrual_mode', 20)->default('monthly'); // monthly, annual, none, custom
            $table->integer('annual_entitlement_hundredths')->default(0); // 2500 = 25.00 days
            $table->integer('max_balance_hundredths')->nullable(); // null = no cap
            $table->integer('carry_over_hundredths')->default(0); // max carry-over
            $table->unsignedTinyInteger('carry_over_deadline_month')->nullable(); // e.g. 3 = end of March
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('market_key', 10)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'wlt_company_code_unique');
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_leave_types');
    }
};
