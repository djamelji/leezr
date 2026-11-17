<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-819 — the referrer sees the name HE typed, frozen : never what the candidate later provided
 * (the application belongs to the candidate and the company, not to the referrer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_referrals', function (Blueprint $t) {
            $t->string('referred_name', 200)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_referrals', function (Blueprint $t) {
            $t->dropColumn('referred_name');
        });
    }
};
