<?php

namespace App\Modules\Workforce\Requirements;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Satisfaction\DocumentProofRegistry;
use App\Core\Documents\Services\RequiredDocumentRequests;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Requirements\RequirementAuthority;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Validation\ValidationException;

/**
 * ADR-819 (Company 2.0) — the people of ONE position and the state of their file against the requirements
 * that really apply to them (effective calculation : role or position, per company), then the EXPLICIT
 * application to people already in place : a preview lists, person by person, the requests that would be
 * created ; nothing is created before confirmation, nothing already held, proven or requested is asked
 * again, historical requests are never touched. Under role authority the position configuration is only
 * prepared : application is refused (the effective rule is still the role's).
 */
final class PositionDocumentApplication
{
    public function __construct(private Company $company, private ?User $actor = null) {}

    /**
     * @param  list<int>|null  $visibleIds  null = every employee of the position, otherwise the caller's scope
     */
    public function people(JobRole $job, ?array $visibleIds): array
    {
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('job_role_id', $job->id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->orderBy('last_name')->get();
        $roles = $this->company->memberships()->whereIn('user_id', $employees->pluck('user_id')->filter())->with('companyRole:id,key')->get()
            ->mapWithKeys(fn ($m) => [$m->user_id => $m->companyRole?->key]);
        $proofs = DocumentProofRegistry::forEmployees($this->company, $employees->pluck('id')->all());
        $authority = RequirementAuthority::of($this->company->id);

        $rows = $employees->map(function (Employee $e) use ($roles, $proofs) {
            $subject = $e->user_id ? DocumentSubject::user((int) $e->user_id) : DocumentSubject::employee((int) $e->id);
            $map = DocumentResolverService::subjectMap($this->company->id, $e->user_id ? ($roles[$e->user_id] ?? null) : null, (int) $e->job_role_id, $this->company->market_key, DocumentType::SCOPE_COMPANY_USER);
            $held = [];
            $requested = [];
            $toRequest = [];
            foreach ($map as $typeId => $entry) {
                if (! $entry['visible'] || ! $entry['required']) {
                    continue;
                }
                $code = $entry['type']->code;
                if ($this->hasFile($subject, (int) $typeId, $e) || isset($proofs[$e->id.':'.$code])) {
                    $held[] = $code;
                } elseif ($this->openRequest($subject, (int) $typeId)) {
                    $requested[] = $code;
                } else {
                    $toRequest[] = $code;
                }
            }

            return [
                'id' => $e->id, 'name' => trim($e->first_name.' '.$e->last_name), 'status' => $e->status,
                'preparatory' => $e->isPreparatory(), 'account' => $e->user_id !== null,
                'held' => $held, 'requested' => $requested, 'to_request' => $toRequest,
                'complete' => $requested === [] && $toRequest === [],
            ];
        })->values()->all();

        return [
            'authority' => $authority,
            'can_apply' => $authority === RequirementAuthority::POSITION,
            'people' => $rows,
            'to_request_total' => array_sum(array_map(fn ($r) => $r['preparatory'] || $r['status'] === Employee::STATUS_TERMINATED ? 0 : count($r['to_request']), $rows)),
        ];
    }

    /**
     * Creates the missing requests for the selected people (explicit confirmation). Preparatory records
     * (their hire file collects them) and terminated employees are skipped.
     *
     * @param  list<int>  $employeeIds
     * @return array{created: array<int, list<string>>, skipped: list<int>}
     */
    public function apply(JobRole $job, array $employeeIds, ?array $visibleIds): array
    {
        if (RequirementAuthority::of($this->company->id) !== RequirementAuthority::POSITION) {
            throw ValidationException::withMessages(['authority' => __('requirements.apply_requires_position_authority')]);
        }
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('job_role_id', $job->id)
            ->whereIn('id', $employeeIds)->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))->get();
        $created = [];
        $skipped = [];
        foreach ($employees as $e) {
            if ($e->isPreparatory() || $e->status === Employee::STATUS_TERMINATED) {
                $skipped[] = $e->id;

                continue;
            }
            $codes = RequiredDocumentRequests::ensureForEmployee($this->company, $e, $this->actor);
            if ($codes !== []) {
                $created[$e->id] = $codes;
            }
        }
        app(AuditLogger::class)->logCompany($this->company->id, 'requirements.applied_to_position', 'job_role', (string) $job->id, [
            'actorId' => $this->actor?->id, 'actorType' => 'user',
            'metadata' => ['people' => count($created), 'requests' => array_sum(array_map('count', $created)), 'skipped' => count($skipped)],
        ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function hasFile(DocumentSubject $subject, int $typeId, Employee $e): bool
    {
        return MemberDocument::where('company_id', $this->company->id)->where('document_type_id', $typeId)->whereNotNull('file_path')
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->where('document_subject_id', $e->id))
                ->when($e->user_id, fn ($q2) => $q2->orWhere(fn ($s) => $s->where('document_subject_type', DocumentSubject::TYPE_USER)->where('document_subject_id', $e->user_id))->orWhere('user_id', $e->user_id)))
            ->exists();
    }

    private function openRequest(DocumentSubject $subject, int $typeId): bool
    {
        return DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $typeId)
            ->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id)
            ->whereNotIn('status', [DocumentRequest::STATUS_CANCELLED])->exists();
    }
}
