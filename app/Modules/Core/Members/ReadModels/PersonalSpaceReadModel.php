<?php

namespace App\Modules\Core\Members\ReadModels;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Signature\ReadModels\MySignatureReadModel;

/**
 * ADR-769 (M7 LOT 3) — « Mon espace » : what the CONNECTED PERSON may use for herself in the
 * CURRENT company. One server answer drives the personal navigation and tells the client which
 * self-service APIs exist, so no screen probes a module that is not there (no decorative 403).
 *
 * Self-service is a relation of OWNERSHIP, never a permission : an owner or an administrator gets
 * here exactly what an ordinary member gets — her own documents, payslips, signatures and, only
 * when an HR record is EXPLICITLY linked to her account (`employee.user_id`), her own employment
 * data. No HR record is ever created or guessed for an account.
 */
class PersonalSpaceReadModel
{
    public static function get(Company $company, User $user): array
    {
        $documents = ModuleGate::isActive($company, 'core.documents');
        $workforce = ModuleGate::isActive($company, 'workforce');
        $signature = ModuleGate::isActive($company, 'workforce_signature');

        // Explicit link only ; an inactive (terminated) record keeps its documents but no live tools.
        $employee = $workforce
            ? Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->first()
            : null;
        $activeEmployee = $employee && $employee->status === Employee::STATUS_ACTIVE;

        $capabilities = [
            'profile' => true,
            'documents' => $documents,
            'payslips' => $documents,
            'signatures' => $signature,
            'employment' => (bool) $activeEmployee,
            'time_clock' => (bool) $activeEmployee,
            'planning' => $activeEmployee && ModuleGate::isActive($company, 'workforce_planning'),
            'leave' => $activeEmployee && ModuleGate::isActive($company, 'workforce_leave'),
            'hr_documents' => $activeEmployee && ModuleGate::isActive($company, 'workforce_documents'),
        ];

        return [
            'company_id' => $company->id,
            'has_employee' => (bool) $employee,
            'employee_id' => $employee?->id,
            'capabilities' => $capabilities,
            'counts' => [
                'document_requests' => $documents ? self::openDocumentRequests($company, $user) : 0,
                'payslips' => $documents ? self::payslipCount($company, $user, $employee) : 0,
                'signatures_to_sign' => $signature ? MySignatureReadModel::toSignCount($company, $user) : 0,
            ],
        ];
    }

    /** Requests addressed to me that still expect something from me (to provide, or rejected). */
    private static function openDocumentRequests(Company $company, User $user): int
    {
        return DocumentRequest::where('company_id', $company->id)->where('user_id', $user->id)
            ->whereIn('status', [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_REJECTED, DocumentRequest::STATUS_TO_REPLACE])->count();
    }

    private static function payslipCount(Company $company, User $user, ?Employee $employee): int
    {
        $subjects = PersonSubjectResolver::resolve($company, $user, $employee)['subjects'];

        return PublishedPayslip::where('company_id', $company->id)->current()->whereNull('revoked_at')
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as $s) {
                    $q->orWhere(fn ($w) => $w->where('document_subject_type', $s->type)->where('document_subject_id', $s->id));
                }
            })->count();
    }
}
