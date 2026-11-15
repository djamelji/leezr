<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-787 — additive : per-company monotonic counters (first use : employee numbers).
 * One row per (company, name), read and incremented under row lock — never MAX()+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 64);
            $t->unsignedBigInteger('next_value');
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sequences');
    }
};
