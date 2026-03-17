<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-357: Standardize role archetypes to universal workspace-driven names.
 *
 * Before: 'driver', 'dispatcher', 'management'
 * After:  'field_worker', 'operations_center', 'management'
 */
return new class extends Migration
{
    /**
     * Explicit versioned mapping — single source of truth.
     * 'management' is unchanged and not listed.
     */
    private const ARCHETYPE_MAP = [
        'dispatcher' => 'operations_center',
        'driver' => 'field_worker',
    ];

    public function up(): void
    {
        foreach (self::ARCHETYPE_MAP as $old => $new) {
            $count = DB::table('company_roles')
                ->where('archetype', $old)
                ->count();

            if ($count > 0) {
                DB::table('company_roles')
                    ->where('archetype', $old)
                    ->update(['archetype' => $new]);

                Log::info("[ADR-357] Renamed archetype '{$old}' → '{$new}' on {$count} company_roles");
            }
        }

        // Safety: detect unknown archetypes (custom roles or stale data)
        $knownArchetypes = ['management', 'operations_center', 'field_worker'];
        $unknowns = DB::table('company_roles')
            ->whereNotNull('archetype')
            ->whereNotIn('archetype', $knownArchetypes)
            ->pluck('archetype')
            ->unique();

        if ($unknowns->isNotEmpty()) {
            Log::warning("[ADR-357] Unknown archetypes found after migration: " . $unknowns->implode(', '));
        }
    }

    public function down(): void
    {
        foreach (self::ARCHETYPE_MAP as $old => $new) {
            DB::table('company_roles')
                ->where('archetype', $new)
                ->update(['archetype' => $old]);
        }
    }
};
