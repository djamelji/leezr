<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-799 — additive only.
 *  - convention_collectives : documented scope, legal source, version and validity (nothing invented :
 *    columns stay null until verified) ;
 *  - convention_collective_rules : verification date, population concerned, allowed derogation ;
 *  - jobdomains.convention_refs : conventions RELEVANT to the trade per market (a reference, never a
 *    legal binding) ;
 *  - companies.convention_confirmed_at / _by : the company CONFIRMS its applicable framework.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('convention_collectives', 'scope_description')) {
            Schema::table('convention_collectives', function (Blueprint $t) {
                $t->text('scope_description')->nullable()->after('full_name');
                $t->string('legal_source_url', 500)->nullable()->after('scope_description');
                $t->string('version_label', 100)->nullable()->after('legal_source_url');
                $t->date('effective_from')->nullable()->after('version_label');
                $t->date('effective_until')->nullable()->after('effective_from');
                $t->timestamp('verified_at')->nullable()->after('effective_until');
            });
        }
        if (! Schema::hasColumn('convention_collective_rules', 'verified_at')) {
            Schema::table('convention_collective_rules', function (Blueprint $t) {
                $t->timestamp('verified_at')->nullable()->after('reference');
                $t->json('population')->nullable()->after('verified_at');
                $t->json('derogation')->nullable()->after('population');
            });
        }
        if (! Schema::hasColumn('jobdomains', 'convention_refs')) {
            Schema::table('jobdomains', fn (Blueprint $t) => $t->json('convention_refs')->nullable()->after('terminology'));
        }
        if (! Schema::hasColumn('companies', 'convention_confirmed_at')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->timestamp('convention_confirmed_at')->nullable()->after('default_convention_collective_id');
                $t->unsignedBigInteger('convention_confirmed_by')->nullable()->after('convention_confirmed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['convention_confirmed_at', 'convention_confirmed_by']));
        Schema::table('jobdomains', fn (Blueprint $t) => $t->dropColumn('convention_refs'));
        Schema::table('convention_collective_rules', fn (Blueprint $t) => $t->dropColumn(['verified_at', 'population', 'derogation']));
        Schema::table('convention_collectives', fn (Blueprint $t) => $t->dropColumn(['scope_description', 'legal_source_url', 'version_label', 'effective_from', 'effective_until', 'verified_at']));
    }
};
