<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics Only — commercial availability of a jobdomain for NEW sign-ups, separate from `is_active`
 * (which keeps historical companies, their modules and Platform management working). OFF by default ;
 * only the delivered jobdomain (logistique) is offered. Additive and reversible : no data removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('jobdomains', 'signup_available')) {
            Schema::table('jobdomains', function (Blueprint $table) {
                $table->boolean('signup_available')->default(false)->after('is_active');
            });
        }
        DB::table('jobdomains')->where('key', 'logistique')->update(['signup_available' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('jobdomains', 'signup_available')) {
            Schema::table('jobdomains', fn (Blueprint $table) => $table->dropColumn('signup_available'));
        }
    }
};
