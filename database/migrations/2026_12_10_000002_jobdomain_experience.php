<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-820 — the JobDomain as an EXPERIENCE PROFILE configured from Platform :
 *  - recommended_modules : modules proposed to the companies of the domain (never activated nor bought) ;
 *  - experience          : { group_order: [nav group keys first], entry_points: [nav item keys] } read by the
 *                          company navigation and the home shortcuts. Nullable = neutral experience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobdomains', function (Blueprint $t) {
            if (! Schema::hasColumn('jobdomains', 'recommended_modules')) {
                $t->json('recommended_modules')->nullable()->after('default_modules');
            }
            if (! Schema::hasColumn('jobdomains', 'experience')) {
                $t->json('experience')->nullable()->after('recommended_modules');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jobdomains', function (Blueprint $t) {
            $t->dropColumn(['recommended_modules', 'experience']);
        });
    }
};
