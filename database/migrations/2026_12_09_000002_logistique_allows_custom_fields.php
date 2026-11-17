<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-819 (Company 2.0) — a Logistique company can create its own person fields (catalogue « Créé par vous »).
 * Targeted : only the flag of the logistique business profile ; nothing is created for any company.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobdomains')->where('key', 'logistique')->update(['allow_custom_fields' => true]);
    }

    public function down(): void
    {
        DB::table('jobdomains')->where('key', 'logistique')->update(['allow_custom_fields' => false]);
    }
};
