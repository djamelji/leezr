<?php

namespace App\Modules\Workforce\Documents;

use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\Satisfaction\DocumentProofSource;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\EmploymentContract;

/**
 * ADR-819 (Company 2.0) — a contract generated AND signed through Workforce satisfies the « work_contract »
 * requirement, exactly like a signed contract uploaded by hand. One requirement, two compatible sources.
 *
 * Conditions (all required) :
 *  - module `workforce_contract` active for the company (owner of contract documents) ;
 *  - same company, the person's CURRENT contract, which has taken effect (active or suspended — never a
 *    draft nor a cancelled draft) ;
 *  - the LATEST document generated for that contract is signed : a regenerated document replaces the
 *    previous one, so an older signature never hides a newer unsigned / declined version ;
 *  - signature_status = signed (a draft or a document sent for signature is not a signed contract).
 * The file is never copied : the proof references the generated document.
 */
final class SignedContractProof implements DocumentProofSource
{
    public const SOURCE = 'workforce.signed_contract';

    public const CODE = 'work_contract';

    /**
     * When the latest contract document becomes SIGNED, an open « work_contract » request still waiting for
     * a file (status requested, nothing submitted) has no reason to stay open : it is closed with a traceable
     * note. A submitted / in-review request is left to its reviewer. Nothing is copied, nothing is deleted.
     */
    public static function register(): void
    {
        GeneratedDocument::updated(function (GeneratedDocument $doc) {
            if ($doc->subject_type !== 'contract' || ! $doc->wasChanged('signature_status') || $doc->signature_status !== GeneratedDocument::SIGNATURE_SIGNED) {
                return;
            }
            $company = Company::find($doc->company_id);
            $contract = EmploymentContract::withoutGlobalScopes()->where('company_id', $doc->company_id)->find($doc->subject_id);
            if (! $company || ! $contract || ! (new self)->proofsFor($company, [(int) $contract->employee_id])) {
                return;
            }
            $employee = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->find($contract->employee_id);
            $typeId = \App\Core\Documents\DocumentType::where('code', self::CODE)->value('id');
            if (! $employee || ! $typeId) {
                return;
            }
            $subjects = array_values(array_filter([
                \App\Core\Documents\DocumentSubject::employee((int) $employee->id),
                $employee->user_id ? \App\Core\Documents\DocumentSubject::user((int) $employee->user_id) : null,
            ]));
            $service = app(\App\Core\Documents\Services\DocumentRequestService::class);
            foreach ($subjects as $subject) {
                $request = $service->findExisting($company->id, $subject, (int) $typeId);
                if ($request && $request->status === \App\Core\Documents\DocumentRequest::STATUS_REQUESTED) {
                    $service->cancel($request, null, __('documents.proof.request_closed'));
                }
            }
        });
    }

    public function documentTypeCodes(): array
    {
        return [self::CODE];
    }

    public function proofsFor(Company $company, array $employeeIds): array
    {
        if ($employeeIds === [] || ! ModuleGate::isActive($company, 'workforce_contract')) {
            return [];
        }
        $contracts = EmploymentContract::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('employee_id', $employeeIds)
            ->where('is_current', true)
            ->whereIn('status', [EmploymentContract::STATUS_ACTIVE, EmploymentContract::STATUS_SUSPENDED])
            ->get(['id', 'employee_id']);
        if ($contracts->isEmpty()) {
            return [];
        }
        $latest = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subject_type', 'contract')
            ->whereIn('subject_id', $contracts->pluck('id'))
            ->orderByDesc('generated_at')->orderByDesc('id')
            ->get(['id', 'subject_id', 'signature_status', 'signed_at', 'generated_at'])
            ->unique('subject_id');

        $out = [];
        foreach ($latest as $doc) {
            if ($doc->signature_status !== GeneratedDocument::SIGNATURE_SIGNED) {
                continue;
            }
            $employeeId = (int) $contracts->firstWhere('id', $doc->subject_id)->employee_id;
            $out[$employeeId][self::CODE] = [
                'source' => self::SOURCE,
                'reference' => 'generated_document:'.$doc->id,
                'label' => __('documents.proof.signed_contract'),
                'at' => $doc->signed_at?->toIso8601String(),
            ];
        }

        return $out;
    }
}
