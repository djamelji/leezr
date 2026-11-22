<?php

namespace App\Core\Requirements;

use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Satisfaction\DocumentProofRegistry;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldObligationSources;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use Illuminate\Support\Collection;

/**
 * ADR-859 (Leezr 5.0 L6) — completeness of an EMPLOYEE file (the person and its HR record), with or without a login
 * account. No new engine : the obligation decisions are the ones the product already applies —
 *   fields    : FieldObligationSources = the form engine's own inputs (Platform catalogue, active modules, JobDomain
 *               when the authority is the role, company-wide rule, the access-role overlay of a linked account), plus
 *               the POSITION requirements of the registry when the company's authority is the position (ADR-818) ;
 *   documents : DocumentResolverService::subjectMap (role authority → activation + role doc_config ; position
 *               authority → activation + position registry), exactly what the document screens use.
 * Presence : EmployeeFieldStore::presence (employee / linked user / membership values, one query set) and the
 * document vault (file present, by employee subject or linked user) + module proofs (DocumentProofRegistry).
 *
 * Stage (registry `stage`, ADR-819) : while the person is still being onboarded (`preparatory`), an item not due at
 * the `hire` stage may be missing without making the file « incomplete » — it is reported as `to_collect`
 * (acceptable for now). Once active, every required item counts.
 *
 * No value is ever returned : only labels and counts (an HR overview, never the data itself).
 */
final class PersonFileCompleteness
{
    /**
     * Profile fields whose value the HR record already HOLDS natively (PP2 : never ask again what is known). Read only :
     * the completeness counts them as filled, nothing is copied. The profile value, when present, also counts.
     */
    private const NATIVE = [
        'hire_date' => 'hire_date',
        'employee_status' => 'status',
        'contract_type' => 'contract',
        'job_title' => 'position',
    ];

    private static function nativelyKnown(string $code, Employee $e): bool
    {
        return match (self::NATIVE[$code] ?? null) {
            'hire_date' => $e->hire_date !== null,
            'status' => ! empty($e->status),
            'contract' => ! empty($e->currentContract?->contract_type),
            'position' => $e->job_role_id !== null,
            default => false,
        };
    }

    /**
     * @param  Collection<int, Employee>  $employees  employees of $company (others are ignored — tenant guard)
     * @return array<int, array{filled: int, total: int, complete: bool, missing: list<array{kind: string, code: string, label: string}>, to_collect: int}>
     */
    public static function forEmployees(Company $company, Collection $employees, array $roleKeyByUser = []): array
    {
        $employees = $employees->filter(fn ($e) => (int) $e->company_id === (int) $company->id)->values();
        if ($employees->isEmpty()) {
            return [];
        }
        $position = RequirementAuthority::isPosition($company->id);
        $sources = FieldObligationSources::forCompany($company);
        $store = new EmployeeFieldStore($company);
        $defs = $store->definitions(); // active person fields of the company (market-filtered)
        $presence = $store->presence($employees);
        // position requirements count in BOTH authorities ; in a `role` company only the company's OWN decisions (positions
        // page / catalogue « require for a position »), never Platform templates or module proposals (not operational there)
        $resolver = EffectiveRequirementResolver::forCompany($company);
        $counts = fn (?array $req) => $req && $req['required'] && ($position || ($req['retained']['source'] ?? null) === 'company');
        $locale = \App\Core\Fields\FieldResolverService::requestLocale();
        $roleTagMap = null;
        $roleTags = function (string $key) use ($company, &$roleTagMap) {
            $roleTagMap ??= \App\Company\RBAC\CompanyRole::where('company_id', $company->id)->get(['key', 'required_tags'])->pluck('required_tags', 'key')->all();

            return (array) ($roleTagMap[$key] ?? []);
        };

        // documents : one decision per (role, position) pair — memoised
        $docMaps = [];
        $docMap = function (?string $roleKey, ?int $jobRoleId) use ($company, &$docMaps) {
            $k = ($roleKey ?? '-').'|'.($jobRoleId ?? '-');

            return $docMaps[$k] ??= DocumentResolverService::subjectMap($company->id, $roleKey, $jobRoleId, $company->market_key, DocumentType::SCOPE_COMPANY_USER);
        };
        $vault = MemberDocument::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereNotNull('file_path')->where('file_path', '!=', '')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('document_subject_type', 'employee')->whereIn('document_subject_id', $employees->pluck('id')))
                ->orWhereIn('user_id', $employees->pluck('user_id')->filter()))
            ->get(['document_type_id', 'document_subject_type', 'document_subject_id', 'user_id']);
        $proofs = DocumentProofRegistry::forEmployees($company, $employees->pluck('id')->all());

        $out = [];
        foreach ($employees as $e) {
            $roleKey = $e->user_id ? ($roleKeyByUser[$e->user_id] ?? null) : null;
            $activities = null; // ADR-861 : resolved lazily, only when a module field is scoped by function
            $positionReq = $resolver->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($e, $e->currentContract?->contract_type));
            $preparatory = method_exists($e, 'isPreparatory') && $e->isPreparatory();
            $deferrable = fn (?string $stage) => $preparatory && $stage !== 'hire';
            $missing = [];
            $toCollect = 0;
            $total = 0;
            $filled = 0;

            // ── fields ──
            foreach ($defs as $def) {
                $o = $sources[$def->code] ?? null;
                $req = $positionReq['field:'.$def->id] ?? null;
                $byModule = $o && $o['modules'] && (! $o['modules_for'] || array_intersect($o['modules_for'],
                    $activities ??= \App\Core\Fields\FunctionActivity::tagsForEmployee($company->id, $e, $roleKey ? $roleTags($roleKey) : null)));
                $byEngine = $o && ($o['platform'] || $byModule || $o['jobdomain'] || $o['company'] || ($roleKey !== null && in_array($roleKey, $o['role_keys'] ?? [], true)));
                $byPosition = $counts($req);
                if ($roleKey !== null && in_array($roleKey, $o['hidden_role_keys'] ?? [], true) && ! $byPosition) {
                    continue; // the form drops a field hidden for the account's role : not asked, not counted
                }
                if (! $byEngine && ! $byPosition) {
                    continue;
                }
                $total++;
                if (($presence[$e->id][$def->id] ?? EmployeeFieldStore::STATUS_MISSING) === EmployeeFieldStore::STATUS_FILLED || self::nativelyKnown($def->code, $e)) {
                    $filled++;
                } elseif ($deferrable($req['retained']['stage'] ?? null)) {
                    $toCollect++;
                } else {
                    $missing[] = ['kind' => 'field', 'code' => $def->code, 'label' => $def->resolvedLabel($locale)];
                }
            }

            // ── documents ──
            foreach ($docMap($roleKey, $e->job_role_id ? (int) $e->job_role_id : null) as $typeId => $entry) {
                $docReq = $positionReq['document:'.$typeId] ?? null;
                $required = (! empty($entry['required']) && ($entry['visible'] ?? true) !== false) || $counts($docReq);
                if (! $required) {
                    continue;
                }
                $type = $entry['type'];
                $total++;
                $has = $vault->contains(fn ($d) => (int) $d->document_type_id === (int) $typeId
                        && (($d->document_subject_type === 'employee' && (int) $d->document_subject_id === (int) $e->id) || ($e->user_id && (int) $d->user_id === (int) $e->user_id)))
                    || isset($proofs[$e->id.':'.$type->code]);
                if ($has) {
                    $filled++;
                } elseif ($deferrable($positionReq['document:'.$typeId]['retained']['stage'] ?? null) && empty($entry['mandatory'])) {
                    $toCollect++;
                } else {
                    $missing[] = ['kind' => 'document', 'code' => $type->code, 'label' => $type->label];
                }
            }

            $out[$e->id] = ['filled' => $filled, 'total' => $total, 'complete' => $missing === [], 'missing' => $missing, 'to_collect' => $toCollect];
        }

        return $out;
    }
}
