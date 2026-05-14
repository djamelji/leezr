<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Documents\GeneratedDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class GeneratedDocumentReadModel
{
    /**
     * Paginated list of generated documents for a company.
     */
    public static function forCompany(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['template:id,code,name,subject_type', 'generatedByUser:id,first_name,last_name']);

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['template_code'])) {
            $query->whereHas('template', fn ($q) => $q->where('code', $filters['template_code']));
        }

        if (! empty($filters['signature_status'])) {
            $query->where('signature_status', $filters['signature_status']);
        }

        return $query->orderByDesc('generated_at')->paginate($perPage);
    }

    /**
     * Get all generated documents for a specific subject.
     */
    public static function forSubject(string $subjectType, int $subjectId, int $companyId): Collection
    {
        return GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['template:id,code,name', 'generatedByUser:id,first_name,last_name'])
            ->orderByDesc('generated_at')
            ->get();
    }

    /**
     * Get all generated documents for an employee (across all subject types).
     */
    public static function forEmployee(int $employeeId, int $companyId): Collection
    {
        // Direct employee docs
        $employeeDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'employee')
            ->where('subject_id', $employeeId)
            ->with(['template:id,code,name'])
            ->get();

        // Contract docs
        $contractIds = \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->pluck('id');

        $contractDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'contract')
            ->whereIn('subject_id', $contractIds)
            ->with(['template:id,code,name'])
            ->get();

        // Leave request docs
        $leaveIds = \App\Core\Workforce\LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->pluck('id');

        $leaveDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'leave_request')
            ->whereIn('subject_id', $leaveIds)
            ->with(['template:id,code,name'])
            ->get();

        return $employeeDocs->merge($contractDocs)->merge($leaveDocs)
            ->sortByDesc('generated_at')
            ->values();
    }

    /**
     * Statistics for a company's generated documents.
     */
    public static function statistics(int $companyId): array
    {
        $docs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('subject_type, signature_status, COUNT(*) as count')
            ->groupBy('subject_type', 'signature_status')
            ->get();

        $bySubject = [];
        $bySignature = [];
        $total = 0;

        foreach ($docs as $row) {
            $bySubject[$row->subject_type] = ($bySubject[$row->subject_type] ?? 0) + $row->count;
            $bySignature[$row->signature_status] = ($bySignature[$row->signature_status] ?? 0) + $row->count;
            $total += $row->count;
        }

        return [
            'total' => $total,
            'by_subject_type' => $bySubject,
            'by_signature_status' => $bySignature,
        ];
    }

    /**
     * Get all payslip drafts for a PayrollRun (via its lines).
     */
    public static function forPayrollRun(int $payrollRunId, int $companyId): Collection
    {
        $lineIds = \App\Core\Workforce\PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $payrollRunId)
            ->where('company_id', $companyId)
            ->pluck('id');

        return GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'payroll_line')
            ->whereIn('subject_id', $lineIds)
            ->with(['template:id,code,name', 'generatedByUser:id,first_name,last_name'])
            ->orderByDesc('generated_at')
            ->get();
    }
}
