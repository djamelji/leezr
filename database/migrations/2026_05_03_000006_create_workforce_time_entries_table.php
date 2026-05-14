<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('clock_in');
            $table->dateTime('clock_out')->nullable();
            $table->string('status', 20)->default('idle'); // idle, working, on_break, completed
            $table->integer('total_worked_minutes')->nullable();
            $table->integer('total_break_minutes')->default(0);
            $table->string('source', 20)->default('manual'); // manual, mobile, kiosk, import
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['employee_id', 'date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_time_entries');
    }
};
