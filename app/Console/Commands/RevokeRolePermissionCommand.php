<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-787 — TARGETED correction of an existing role : removes ONE permission from the roles with ONE
 * key (optionally in listed companies), nothing else. Simulation by default ; `--apply` writes and
 * audits each change. Never a preset realignment (unlike permissions:reconcile --apply).
 *
 *   php artisan roles:revoke-permission driver members.invite            # simulation, all companies
 *   php artisan roles:revoke-permission driver members.invite --company=1 --apply
 */
class RevokeRolePermissionCommand extends Command
{
    protected $signature = 'roles:revoke-permission {role_key} {permission} {--company=* : limit to these company ids} {--apply}';

    protected $description = 'Remove one permission from the roles with a given key (simulation unless --apply) — ADR-787';

    public function handle(): int
    {
        $permission = CompanyPermission::where('key', $this->argument('permission'))->first();
        if (! $permission) {
            $this->error('Unknown permission.');

            return self::FAILURE;
        }
        $roles = CompanyRole::where('key', $this->argument('role_key'))
            ->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->whereHas('permissions', fn ($q) => $q->whereKey($permission->id))->get();

        $report = ['apply' => (bool) $this->option('apply'), 'permission' => $permission->key, 'roles' => []];
        foreach ($roles as $role) {
            $memberships = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
                ->where('company_role_id', $role->id)->orderBy('memberships.id')->get(['memberships.id', 'users.email']);
            $report['roles'][] = ['company_id' => $role->company_id, 'role_id' => $role->id, 'members' => $memberships->count(),
                'memberships' => $memberships->map(fn ($m) => ['membership_id' => $m->id, 'email' => $m->email])->all()];
            if ($this->option('apply')) {
                $role->permissions()->detach($permission->id);
                app(AuditLogger::class)->logCompany($role->company_id, 'role.permission_revoked', 'company_role', (string) $role->id, [
                    'severity' => 'warning', 'metadata' => ['permission' => $permission->key, 'source' => 'roles:revoke-permission (ADR-787)'],
                ]);
            }
        }
        $this->line(json_encode($report));

        return self::SUCCESS;
    }
}
