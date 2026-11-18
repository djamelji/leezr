<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-825 — CONTROLLED remediation of historical delivery-driver roles (audit : rbac:driver-role-audit).
 *
 *  - SIMULATION by default : per company and role, what would be removed / kept, members, customisation ;
 *    nothing is written.
 *  - --apply needs --confirm-database=<current database name> (guard against running on the wrong base) ;
 *    one transaction per role, every change audited (`role.remediation_applied`, before / after, batch id).
 *  - Safe resume : a role already compliant is skipped ; rerunning after an interruption only finishes the rest.
 *  - Controlled restore : --restore=<batch> re-attaches exactly what the batch removed (simulation unless
 *    --apply + --confirm-database), audited (`role.remediation_restored`).
 *  - Roles with explicit customisations (role audit trail) are SKIPPED unless listed with --role=<id> :
 *    the company changed that role on purpose, its decision is needed.
 *
 * Removes only : members.view, settings.view, documents.view. Delivery rights (shipments.view_own,
 * shipments.manage_status) are never touched ; a role missing them is reported, not changed.
 */
class RbacDriverRoleRemediateCommand extends Command
{
    protected $signature = 'rbac:driver-role-remediate
        {--company=* : limit to these company ids}
        {--role=* : explicit role ids (also allows customised roles)}
        {--apply : write the changes}
        {--confirm-database= : required with --apply : the current database name}
        {--restore= : batch id to restore}';

    protected $description = 'Controlled remediation of historical delivery-driver roles (simulation unless --apply) — ADR-825';

    public const REMOVE = ['members.view', 'settings.view', 'documents.view'];

    public const DELIVERY = ['shipments.view_own', 'shipments.manage_status'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm-database') !== DB::connection()->getDatabaseName()) {
            $this->error('Refused : --apply requires --confirm-database='.DB::connection()->getDatabaseName().' (explicit confirmation of the target database).');

            return self::FAILURE;
        }

        return $this->option('restore') ? $this->restore((string) $this->option('restore'), $apply) : $this->remediate($apply);
    }

    private function remediate(bool $apply): int
    {
        $perm = CompanyPermission::whereIn('key', array_merge(self::REMOVE, self::DELIVERY))->pluck('id', 'key');
        $explicit = array_map('intval', (array) $this->option('role'));
        $roles = CompanyRole::query()->where('key', 'driver')
            ->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->when($explicit, fn ($q) => $q->whereIn('id', $explicit))
            ->orderBy('company_id')->get();
        $batch = $apply ? 'drv-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)) : null;
        $rows = [];
        foreach ($roles as $role) {
            $held = $role->permissions()->pluck('key')->all();
            $remove = array_values(array_intersect(self::REMOVE, $held));
            $customised = DB::table('company_audit_logs')->where('company_id', $role->company_id)->where('target_id', (string) $role->id)
                ->where('action', 'like', 'role.%')->whereNotIn('action', ['role.remediation_applied', 'role.remediation_restored', 'role.permission_revoked'])->count();
            $row = ['company_id' => $role->company_id, 'role_id' => $role->id,
                'members' => DB::table('memberships')->where('company_role_id', $role->id)->count(),
                'remove' => $remove, 'keep' => array_values(array_diff($held, $remove)),
                'delivery_rights' => array_values(array_intersect(self::DELIVERY, $held)),
                'explicit_customisations' => $customised];
            $row['status'] = match (true) {
                $remove === [] => 'already_compliant',
                $customised > 0 && ! in_array($role->id, $explicit, true) => 'skipped_customised',
                default => $apply ? 'applied' : 'would_apply',
            };
            if (array_diff(self::DELIVERY, $held) !== []) {
                $row['warning'] = 'delivery rights incomplete before remediation (left unchanged)';
            }
            if ($apply && $row['status'] === 'applied') {
                DB::transaction(function () use ($role, $remove, $held, $perm, $batch) {
                    $role->permissions()->detach(array_values(array_map(fn ($k) => $perm[$k], $remove)));
                    app(AuditLogger::class)->logCompany($role->company_id, 'role.remediation_applied', 'company_role', (string) $role->id, [
                        'actorType' => 'system', 'actorId' => null, 'severity' => 'warning',
                        'diffBefore' => ['permissions' => array_values($held)],
                        'diffAfter' => ['permissions' => array_values(array_diff($held, $remove))],
                        'metadata' => ['batch' => $batch, 'removed' => $remove, 'source' => 'rbac:driver-role-remediate (ADR-825)'],
                    ]);
                });
            }
            $rows[] = $row;
        }
        $this->line(json_encode(['database' => DB::connection()->getDatabaseName(), 'mode' => $apply ? 'apply' : 'simulation', 'batch' => $batch,
            'summary' => collect($rows)->countBy('status')->all(), 'roles' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function restore(string $batch, bool $apply): int
    {
        $logs = DB::table('company_audit_logs')->where('action', 'role.remediation_applied')->get()
            ->filter(fn ($l) => (json_decode((string) $l->metadata, true)['batch'] ?? null) === $batch);
        $perm = CompanyPermission::whereIn('key', self::REMOVE)->pluck('id', 'key');
        $rows = [];
        foreach ($logs as $log) {
            $role = CompanyRole::find((int) $log->target_id);
            $removed = json_decode((string) $log->metadata, true)['removed'] ?? [];
            if (! $role) {
                $rows[] = ['role_id' => (int) $log->target_id, 'status' => 'role_missing'];

                continue;
            }
            $missing = array_values(array_diff($removed, $role->permissions()->pluck('key')->all()));
            $row = ['company_id' => $role->company_id, 'role_id' => $role->id, 'restore' => $missing,
                'status' => $missing === [] ? 'already_restored' : ($apply ? 'restored' : 'would_restore')];
            if ($apply && $missing !== []) {
                DB::transaction(function () use ($role, $missing, $perm, $batch) {
                    $role->permissions()->syncWithoutDetaching(array_values(array_map(fn ($k) => $perm[$k], $missing)));
                    app(AuditLogger::class)->logCompany($role->company_id, 'role.remediation_restored', 'company_role', (string) $role->id, [
                        'actorType' => 'system', 'actorId' => null, 'severity' => 'warning',
                        'metadata' => ['batch' => $batch, 'restored' => $missing, 'source' => 'rbac:driver-role-remediate --restore (ADR-825)'],
                    ]);
                });
            }
            $rows[] = $row;
        }
        $this->line(json_encode(['database' => DB::connection()->getDatabaseName(), 'mode' => $apply ? 'restore' : 'restore-simulation', 'batch' => $batch,
            'summary' => collect($rows)->countBy('status')->all(), 'roles' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
