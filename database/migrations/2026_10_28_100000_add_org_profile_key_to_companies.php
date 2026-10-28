<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-636 (W-E) — discriminant de profil organisationnel de la Company. ADDITIF, nullable (fallback),
 * compatible avec toutes les Companies existantes ; jamais rétroactif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('org_profile_key', 60)->nullable()->after('jobdomain_key');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('org_profile_key');
        });
    }
};
