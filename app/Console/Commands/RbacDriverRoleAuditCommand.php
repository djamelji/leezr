<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-824 — READ-ONLY audit of the historical « delivery driver » roles (ADR-823 limited the default role of
 * new companies). For each existing driver role : rights held beyond the delivery work, their probable origin,
 * explicit customisations, consequences, and a remediation PREVIEW. Nothing is written ; no personal data is
 * printed (company ids and names, member counts only). Applying a remediation needs a specific authorisation.
 */
class RbacDriverRoleAuditCommand extends Command
{
    protected $signature = 'rbac:driver-role-audit {--company=* : company ids (default: all)}';

    protected $description = 'Read-only audit of historical delivery-driver roles with a remediation preview (ADR-824)';

    /** Rights the delivery work needs (own deliveries, status) — the personal space needs none. */
    private const NEEDED = ['shipments.view_own', 'shipments.manage_status', 'theme.view', 'theme.manage'];

    /** Rights reviewed, with the consequence of keeping them. */
    private const REVIEWED = [
        'members.view' => 'opens colleagues\' profiles and personal fields (/company/members/{id}, /fields) and the account facet of person files',
        'documents.view' => 'opens the company vault and colleagues\' documents (documentary supervision)',
        'settings.view' => 'reads company settings ; no operational use for a driver',
        'members.invite' => 'can invite accounts',
        'members.manage' => 'can change or remove accounts',
        'settings.manage' => 'can change company settings',
        'roles.view' => 'reads roles and every permission',
        'workforce.view' => 'opens HR files of others',
    ];

    public function handle(): int
    {
        $perm = DB::table('company_permissions')->pluck('key', 'id');
        $roles = CompanyRole::query()->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->where('key', 'driver')->orderBy('company_id')->get();
        $companies = DB::table('companies')->whereIn('id', $roles->pluck('company_id'))->get(['id', 'name', 'jobdomain_key', 'created_at'])->keyBy('id');
        $rows = [];
        foreach ($roles as $role) {
            $held = DB::table('company_role_permission')->where('company_role_id', $role->id)->pluck('company_permission_id')
                ->map(fn ($id) => $perm[$id] ?? null)->filter()->values()->all();
            $excess = array_values(array_intersect(array_keys(self::REVIEWED), $held));
            $preset = DB::table('preset_applications')->where('company_id', $role->company_id)->where('kind', 'rbac')
                ->orderByDesc('id')->first(['preset_key', 'preset_version', 'created_at']);
            $edits = DB::table('company_audit_logs')->where('company_id', $role->company_id)
                ->where('action', 'like', 'role.%')->where('target_id', (string) $role->id)->count();
            $company = $companies[$role->company_id] ?? null;
            $rows[] = [
                'company_id' => $role->company_id,
                'company' => $company?->name,
                'role_id' => $role->id,
                'members' => DB::table('memberships')->where('company_role_id', $role->id)->count(),
                'administrative' => (bool) $role->is_administrative,
                'needed_for_delivery' => array_values(array_intersect(self::NEEDED, $held)),
                'reviewed_rights' => $excess,
                'origin' => $preset
                    ? "preset {$preset->preset_key} v{$preset->preset_version} applied ".substr((string) $preset->created_at, 0, 10)
                    : 'default role of the jobdomain registry at company creation ('.substr((string) $company?->created_at, 0, 10).')',
                'explicit_customisations' => $edits,
                'consequences' => array_map(fn ($p) => "$p : ".self::REVIEWED[$p], $excess),
                'delivery_impact_of_removal' => 'none — '.(array_diff(['shipments.view_own', 'shipments.manage_status'], $held) === [] ? 'delivery rights stay' : 'WARNING: delivery rights already incomplete'),
                'remediation_preview' => $excess === [] ? null : [
                    'remove' => $excess,
                    'keep' => array_values(array_diff($held, $excess)),
                    'requires' => $edits > 0 ? 'company decision (the role was customised explicitly)' : 'company or Platform decision with the company\'s agreement',
                ],
            ];
        }
        $summary = [
            'driver_roles' => count($rows),
            'with_reviewed_rights' => count(array_filter($rows, fn ($r) => $r['reviewed_rights'] !== [])),
            'members_concerned' => array_sum(array_map(fn ($r) => $r['reviewed_rights'] !== [] ? $r['members'] : 0, $rows)),
            'by_right' => collect($rows)->flatMap(fn ($r) => $r['reviewed_rights'])->countBy()->all(),
        ];
        $this->line(json_encode(['generated_at' => now()->toIso8601String(), 'read_only' => true, 'applied' => false, 'summary' => $summary, 'roles' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
