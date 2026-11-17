<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-818 (Logistics Only) — recommended roles for NEW logistics companies : the dispatcher and the operations
 * manager process documents (`documents.processing`) but no longer configure document types (`documents.configure`
 * came with `documents.management`). Targeted change of the platform catalogue only (the rest of default_roles,
 * possibly edited by Platform, is untouched). Existing companies' roles and pivots are NOT modified (proposals :
 * `php artisan rbac:least-privilege-report`). Reversible.
 */
return new class extends Migration
{
    private const ROLES = ['dispatcher', 'ops_manager'];

    public function up(): void
    {
        $this->swap('documents.management', 'documents.processing');
    }

    public function down(): void
    {
        $this->swap('documents.processing', 'documents.management');
    }

    private function swap(string $from, string $to): void
    {
        $row = DB::table('jobdomains')->where('key', 'logistique')->first(['id', 'default_roles']);
        if (! $row || ! $row->default_roles) {
            return;
        }
        $roles = json_decode($row->default_roles, true);
        foreach (self::ROLES as $key) {
            if (! isset($roles[$key]['bundles']) || ! in_array($from, $roles[$key]['bundles'], true)) {
                continue;
            }
            $roles[$key]['bundles'] = array_values(array_unique(array_map(fn ($b) => $b === $from ? $to : $b, $roles[$key]['bundles'])));
        }
        DB::table('jobdomains')->where('id', $row->id)->update(['default_roles' => json_encode($roles, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
};
