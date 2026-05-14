<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['internal', 'client_site', 'remote', 'mobile'])->default('internal');
            $table->string('client_name')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_work_locations');
    }
};
