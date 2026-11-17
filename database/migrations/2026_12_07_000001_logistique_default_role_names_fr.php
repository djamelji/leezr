<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-813 — default role NAMES of the `logistique` jobdomain definition, aligned on the published
 * Logistics RBAC preset. Touches the jobdomain definition only (used when a NEW company is created) :
 * never company_roles, keys, permissions. A name already edited on the platform is left as is.
 */
return new class extends Migration
{
    private const RENAMES = [
        'manager' => ['Manager', 'Responsable'],
        'dispatcher' => ['Dispatcher', 'Répartiteur'],
        'driver' => ['Driver', 'Chauffeur-livreur'],
        'ops_manager' => ['Operations Manager', 'Responsable d’exploitation'],
    ];

    public function up(): void
    {
        $this->apply(0, 1);
    }

    public function down(): void
    {
        $this->apply(1, 0);
    }

    private function apply(int $from, int $to): void
    {
        $row = DB::table('jobdomains')->where('key', 'logistique')->first();
        if (! $row || ! $row->default_roles) {
            return;
        }
        $roles = json_decode($row->default_roles, true) ?: [];
        $changed = false;
        foreach (self::RENAMES as $key => $names) {
            if (($roles[$key]['name'] ?? null) === $names[$from]) {
                $roles[$key]['name'] = $names[$to];
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('jobdomains')->where('id', $row->id)->update([
                'default_roles' => json_encode($roles, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
