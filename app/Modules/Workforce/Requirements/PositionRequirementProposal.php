<?php

namespace App\Modules\Workforce\Requirements;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;

/**
 * ADR-817 (L1) — READ-ONLY takeover proposal of the historical RBAC-role rules for ONE position.
 *
 * Today the requirements of a person come from her ACCESS ROLE (`doc_config`, `required_tags` × the
 * archetype, `field_config`) — the target model takes them from the POSITION. For the people of a
 * position, this computes what their roles currently impose BEYOND the rules common to everyone,
 * with its origin, the contradictions between roles of the same position, the current configuration
 * of the position, and the consequences. Nothing is written : the company adopts explicitly through
 * the existing configuration path (RequirementConfigService::commit), item by item. Visibility rules
 * (`visibility_roles`, `visible: false`) are access rules : reported, never carried over.
 */
final class PositionRequirementProposal
{
    public function __construct(private Company $company) {}

    public function for(JobRole $job): array
    {
        $market = $this->company->market_key;
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('job_role_id', $job->id)
            ->where('status', '!=', Employee::STATUS_TERMINATED)->operational()->get(['id', 'user_id']);
        $userIds = $employees->pluck('user_id')->filter()->values();
        $roleOfUser = DB::table('memberships')->where('company_id', $this->company->id)->whereIn('user_id', $userIds)->pluck('company_role_id', 'user_id');
        $roles = CompanyRole::where('company_id', $this->company->id)->whereIn('id', $roleOfUser->filter()->unique())->get()->keyBy('id');
        $peopleByRole = $roleOfUser->filter()->countBy();

        $catalog = (new RequirementConfigService($this->company))->catalog();
        $labels = ['field' => collect($catalog['fields'])->pluck('label', 'code'), 'document' => collect($catalog['documents'])->pluck('label', 'code')];
        $docCodes = DocumentType::pluck('code', 'id');

        // Rules common to everyone (no role) : company activations, modules, market — not a position matter.
        $common = $this->requiredDocuments(DocumentResolverService::requirementMap($this->company->id, null, $market, DocumentType::SCOPE_COMPANY_USER), $docCodes);

        $candidates = []; // "kind:code" => origins
        $hidden = [];
        foreach ($roles as $roleId => $role) {
            $people = (int) ($peopleByRole[$roleId] ?? 0);
            $map = DocumentResolverService::requirementMap($this->company->id, $role->key, $market, DocumentType::SCOPE_COMPANY_USER);
            foreach ($map as $typeId => $e) {
                $code = $docCodes[$typeId] ?? null;
                if (! $code) {
                    continue;
                }
                if (! $e['visible'] && isset($common[$code])) {
                    $hidden[] = ['kind' => 'document', 'code' => $code, 'label' => $labels['document'][$code] ?? $code, 'role' => $role->name, 'people' => $people];
                }
                if ($e['visible'] && $e['required'] && ! isset($common[$code])) {
                    $candidates['document:'.$code][] = ['role' => $role->name, 'role_id' => $roleId, 'people' => $people, 'rule' => $e['mandatory'] ? 'role_tags' : 'role_config'];
                }
            }
            foreach ((array) $role->field_config as $entry) {
                $code = $entry['code'] ?? null;
                if (! $code) {
                    continue;
                }
                if (($entry['required'] ?? false) === true) {
                    $candidates['field:'.$code][] = ['role' => $role->name, 'role_id' => $roleId, 'people' => $people, 'rule' => 'role_config'];
                } elseif (($entry['visible'] ?? true) === false) {
                    $hidden[] = ['kind' => 'field', 'code' => $code, 'label' => $labels['field'][$code] ?? $code, 'role' => $role->name, 'people' => $people];
                }
            }
        }

        $config = new RequirementConfigService($this->company);
        $state = $config->state('job_role:'.$job->id);
        $current = [];
        foreach (['field' => 'fields', 'document' => 'documents'] as $kind => $list) {
            foreach ($state[$list] as $row) {
                $current[$kind.':'.$row['code']] = $row['state'];
            }
        }

        $items = [];
        foreach ($candidates as $key => $origins) {
            [$kind, $code] = explode(':', $key, 2);
            $requiring = collect($origins)->pluck('role_id')->all();
            $notFrom = $roles->reject(fn ($r, $id) => in_array($id, $requiring, true))
                ->map(fn ($r, $id) => ['role' => $r->name, 'people' => (int) ($peopleByRole[$id] ?? 0)])->values()->all();
            $currentState = $current[$key] ?? null;
            $items[] = [
                'kind' => $kind,
                'code' => $code,
                'label' => $labels[$kind][$code] ?? $code,
                'proposed_state' => 'required',
                'current_state' => $currentState,
                'status' => match (true) {
                    ! isset($labels[$kind][$code]) => 'unavailable',               // hors catalogue de la société
                    in_array($currentState, ['required', 'optional'], true) => 'configured',
                    $notFrom !== [] => 'partial',                                    // exigé par une partie des rôles du poste
                    default => 'new',
                },
                'origins' => array_map(fn ($o) => ['role' => $o['role'], 'people' => $o['people'], 'rule' => $o['rule']], $origins),
                'not_from' => $notFrom,
            ];
        }
        usort($items, fn ($a, $b) => [$a['kind'], $a['label']] <=> [$b['kind'], $b['label']]);

        $visibilityRules = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
            ->whereNotNull('visibility_roles')->get(['code', 'visibility_roles'])
            ->filter(fn ($d) => ! empty($d->visibility_roles))->count();

        return [
            'position' => ['id' => $job->id, 'title' => $job->title],
            'people' => [
                'total' => $employees->count(),
                'with_account' => $userIds->count(),
                'accountless' => $employees->whereNull('user_id')->count(),
                'without_role' => $userIds->reject(fn ($u) => ! empty($roleOfUser[$u]))->count(),
            ],
            'roles' => $roles->map(fn ($r, $id) => ['name' => $r->name, 'people' => (int) ($peopleByRole[$id] ?? 0)])->values()->all(),
            'items' => $items,
            'hidden_by_roles' => $hidden,
            'visibility_rules' => $visibilityRules,
            'common_documents' => array_values(array_map(fn ($c) => $labels['document'][$c] ?? $c, array_keys($common))),
        ];
    }

    /** @return array<string, true> codes required and visible in a requirement map */
    private function requiredDocuments(array $map, $docCodes): array
    {
        $out = [];
        foreach ($map as $typeId => $e) {
            if ($e['visible'] && $e['required'] && isset($docCodes[$typeId])) {
                $out[$docCodes[$typeId]] = true;
            }
        }

        return $out;
    }
}
