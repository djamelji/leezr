<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_jobdomain', function (Blueprint $table) {
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('jobdomain_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_jobdomain');
    }
};
