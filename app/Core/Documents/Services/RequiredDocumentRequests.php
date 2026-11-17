<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;

/**
 * ADR-818 (chantier C) — creates the « to provide » requests of the documents a person MUST provide, at an
 * explicit business event (hire finalised, position changed, HR action « request missing documents ») —
 * never during a read. Idempotent : a type that already has a request (whatever its state : open, approved,
 * cancelled) is left untouched — nothing is regenerated, reopened or duplicated. Same chain as manual requests
 * (DocumentRequestService::open). The decision is the shared one (DocumentResolverService::subjectMap).
 */
final class RequiredDocumentRequests
{
    /** @return list<string> document codes newly requested */
    public static function ensureForEmployee(Company $company, Employee $employee, ?User $actor = null): array
    {
        $roleKey = null;
        if ($employee->user_id) {
            $roleKey = $company->memberships()->where('user_id', $employee->user_id)->with('companyRole:id,key')->first()?->companyRole?->key;
        }
        $subject = $employee->user_id ? DocumentSubject::user((int) $employee->user_id) : DocumentSubject::employee((int) $employee->id);

        return self::ensure($company, $subject, $roleKey, $employee->job_role_id ? (int) $employee->job_role_id : null, $actor);
    }

    /** @return list<string> */
    public static function ensure(Company $company, DocumentSubject $subject, ?string $roleKey, ?int $jobRoleId, ?User $actor = null): array
    {
        $map = DocumentResolverService::subjectMap($company->id, $roleKey, $jobRoleId, $company->market_key, DocumentType::SCOPE_COMPANY_USER);
        $service = app(DocumentRequestService::class);
        $created = [];
        $proofs = \App\Core\Documents\Satisfaction\DocumentProofRegistry::forSubjects($company, [$subject]); // ADR-819
        foreach ($map as $typeId => $entry) {
            if (! $entry['visible'] || ! $entry['required']) {
                continue;
            }
            if ($service->findExisting($company->id, $subject, (int) $typeId) !== null) {
                continue; // jamais régénérée, rouverte ni dupliquée
            }
            $hasFile = \App\Core\Documents\MemberDocument::where('company_id', $company->id)->where('document_type_id', $typeId)
                ->where(fn ($q) => $q->where(fn ($s) => $s->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id))
                    ->when($subject->is(DocumentSubject::TYPE_USER), fn ($q2) => $q2->orWhere('user_id', $subject->id)))->exists();
            if ($hasFile || isset($proofs[$entry['type']->code])) {
                continue; // déjà détenue, ou prouvée par une autre source (ex. contrat signé) : rien à demander
            }
            $service->open($company->id, $subject, $entry['type'], $actor);
            $created[] = $entry['type']->code;
        }

        return $created;
    }
}
