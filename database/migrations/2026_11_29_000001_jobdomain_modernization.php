<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-797 (L7) — additive only. JobDomain business identity (sector, terminology) and an optional
 * PINNED version on a preset recommendation (null = latest published). Legacy columns are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('jobdomains', 'sector')) {
            Schema::table('jobdomains', function (Blueprint $t) {
                $t->string('sector', 100)->nullable()->after('description');
                $t->json('terminology')->nullable()->after('sector');
            });
        }
        if (! Schema::hasColumn('jobdomain_preset_links', 'pinned_version')) {
            Schema::table('jobdomain_preset_links', function (Blueprint $t) {
                $t->unsignedInteger('pinned_version')->nullable()->after('family_key');
            });
        }
    }

    public function down(): void
    {
        Schema::table('jobdomain_preset_links', fn (Blueprint $t) => $t->dropColumn('pinned_version'));
        Schema::table('jobdomains', fn (Blueprint $t) => $t->dropColumn(['sector', 'terminology']));
    }
};
