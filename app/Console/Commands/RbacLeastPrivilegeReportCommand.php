<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-818 (Logistics Only) — READ-ONLY report : existing company roles holding a CONFIGURATION right that the
 * new recommended roles no longer grant to operational positions (e.g. `documents.configure` for a dispatcher).
 * Nothing is changed : existing pivots and customisations stay ; each line is a PROPOSAL the company (or Platform,
 * with its agreement) applies explicitly through the role editor.
 */
class RbacLeastPrivilegeReportCommand extends Command
{
    protected $signature = 'rbac:least-privilege-report {--company=* : company ids (default: all)}';

    protected $description = 'Read-only proposals: operational roles holding configuration rights (ADR-818)';

    private const OPERATIONAL_KEYS = ['dispatcher', 'ops_manager', 'driver'];

    private const CONFIG_PERMISSIONS = ['documents.configure'];

    public function handle(): int
    {
        $permIds = DB::table('company_permissions')->whereIn('key', self::CONFIG_PERMISSIONS)->pluck('key', 'id');
        $rows = [];
        $roles = CompanyRole::query()->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->whereIn('id', DB::table('company_role_permission')->whereIn('company_permission_id', $permIds->keys())->pluck('company_role_id'))
            ->orderBy('company_id')->get();
        foreach ($roles as $role) {
            $operational = in_array($role->key, self::OPERATIONAL_KEYS, true) || ! $role->is_administrative;
            if (! $operational) {
                continue;
            }
            $held = DB::table('company_role_permission')->where('company_role_id', $role->id)->whereIn('company_permission_id', $permIds->keys())->pluck('company_permission_id')->map(fn ($id) => $permIds[$id])->all();
            $rows[] = [
                'company_id' => $role->company_id,
                'role' => $role->name,
                'role_key' => $role->key,
                'members' => DB::table('memberships')->where('company_role_id', $role->id)->count(),
                'holds' => $held,
                'proposal' => 'remove '.implode(', ', $held).' (keep document processing: documents.manage) — explicit decision of the company',
            ];
        }
        $this->line(json_encode(['generated_at' => now()->toIso8601String(), 'read_only' => true, 'proposals' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
