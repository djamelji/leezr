<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_schedule_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_duration_minutes')->default(0);
            $table->string('color', 7)->nullable();
            $table->foreignId('work_location_id')->nullable()->constrained('workforce_work_locations')->nullOnDelete();
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
        Schema::dropIfExists('workforce_schedule_templates');
    }
};
