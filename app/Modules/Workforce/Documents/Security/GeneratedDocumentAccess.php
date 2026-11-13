<?php

namespace App\Modules\Workforce\Documents\Security;

use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Http\Request;

/**
 * ADR-771 — single authority for OPENING a generated HR document (original or signed version)
 * from the management surfaces. A generated document belongs to exactly one person, reached through
 * its subject (employee, contract → its employee, leave request → its employee).
 *
 *  - scope   : the actor must be allowed to see that person (HR company view, self, direct report) ;
 *  - content : a document that embeds the pay (employment contract, payslip) additionally requires
 *              `workforce.compensation_read` — being allowed to SEE that a contract exists in the
 *              file is not being allowed to read the salary written in it.
 *
 * The person herself goes through her own self-service route (ownership), never through this one.
 */
class GeneratedDocumentAccess
{
    private const EMBEDS_COMPENSATION = ['contract', 'payroll_line', 'payroll_run'];

    /** The employee a generated document is about (null : company-level document). */
    public static function employeeIdOf(GeneratedDocument $document): ?int
    {
        return match ($document->subject_type) {
            'employee' => (int) $document->subject_id,
            'contract' => self::owner(EmploymentContract::class, $document),
            'leave_request' => self::owner(LeaveRequest::class, $document),
            default => null,
        };
    }

    public static function embedsCompensation(GeneratedDocument $document): bool
    {
        return in_array($document->subject_type, self::EMBEDS_COMPENSATION, true);
    }

    public static function canOpen(Request $request, GeneratedDocument $document): bool
    {
        $employeeId = self::employeeIdOf($document);
        if ($employeeId !== null && ! WorkforceAccess::canViewEmployeeData($request, $employeeId, 'employee')) {
            return false;
        }

        return ! self::embedsCompensation($document) || WorkforceAccess::canReadCompensation($request);
    }

    /** The signed version of a document : only a COMPLETED request with a sealed file counts. */
    public static function signedRequest(GeneratedDocument $document): ?SignatureRequest
    {
        return SignatureRequest::withoutGlobalScopes()->where('company_id', $document->company_id)
            ->where('generated_document_id', $document->id)->where('status', SignatureRequest::STATUS_COMPLETED)
            ->whereNotNull('signed_document_path')->latest('completed_at')->get()->first();
    }

    private static function owner(string $model, GeneratedDocument $document): ?int
    {
        $id = $model::withoutGlobalScopes()->where('company_id', $document->company_id)->where('id', $document->subject_id)->value('employee_id');

        return $id ? (int) $id : null;
    }
}
