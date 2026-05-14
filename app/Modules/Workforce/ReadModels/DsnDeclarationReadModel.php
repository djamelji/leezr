<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\DsnDeclaration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read model for DsnDeclaration queries.
 * All methods are static, read-only — no mutations.
 *
 * Sprint 6.4 — ADR-522, Sprint 6.7 — ADR-525 (enriched)
 */
class DsnDeclarationReadModel
{
    public static function forCompany(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['generatedByUser:id,name', 'exportedByUser:id,name']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['period_month'])) {
            $query->where('period_month', $filters['period_month']);
        }

        return $query->orderByDesc('generated_at')->paginate($perPage);
    }

    public static function forPayrollRun(int $payrollRunId): ?DsnDeclaration
    {
        return DsnDeclaration::withoutGlobalScopes()
            ->where('payroll_run_id', $payrollRunId)
            ->with(['generatedByUser:id,name'])
            ->first();
    }

    public static function latestForPeriod(int $companyId, string $periodMonth): ?DsnDeclaration
    {
        return DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_month', $periodMonth)
            ->orderByDesc('generated_at')
            ->first();
    }

    // ── Sprint 6.7 — ADR-525: Operational readiness ──

    /**
     * Failed/rejected dashboard — declarations needing attention.
     */
    public static function failedAndRejected(int $companyId, int $perPage = 15): LengthAwarePaginator
    {
        return DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('status', DsnDeclaration::STATUS_REJECTED)
                    ->orWhere(function ($q2) {
                        $q2->where('status', DsnDeclaration::STATUS_DRAFT)
                            ->whereNotNull('validation_errors');
                    });
            })
            ->with(['generatedByUser:id,name', 'exportedByUser:id,name'])
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Latest declaration per period for a company.
     * Returns a collection keyed by period_month.
     */
    public static function latestByCompanyPeriod(int $companyId, ?int $limit = 12): Collection
    {
        return DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('period_month')
            ->orderByDesc('generated_at')
            ->get()
            ->unique('period_month')
            ->take($limit)
            ->values();
    }

    /**
     * Validation summary for a specific declaration.
     * Returns structured array with counts, errors, warnings, employee breakdown.
     */
    public static function validationSummary(int $declarationId): array
    {
        $declaration = DsnDeclaration::withoutGlobalScopes()->find($declarationId);

        if (! $declaration) {
            return ['found' => false];
        }

        $entries = $declaration->validation_errors ?? [];
        $errors = array_filter($entries, fn ($e) => ($e['severity'] ?? 'error') === 'error');
        $warnings = array_filter($entries, fn ($e) => ($e['severity'] ?? 'error') === 'warning');

        // Group by category
        $byCategory = [];
        foreach ($entries as $entry) {
            $cat = $entry['category'] ?? 'unknown';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
        }

        // Group by employee
        $byEmployee = [];
        foreach ($entries as $entry) {
            if (! empty($entry['employee_id'])) {
                $eid = $entry['employee_id'];
                $byEmployee[$eid] = ($byEmployee[$eid] ?? 0) + 1;
            }
        }

        return [
            'found' => true,
            'declaration_id' => $declaration->id,
            'status' => $declaration->status,
            'period_month' => $declaration->period_month,
            'payload_hash' => $declaration->payload_hash,
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            'total_entries' => count($entries),
            'by_category' => $byCategory,
            'by_employee' => $byEmployee,
            'entries' => $entries,
        ];
    }

    /**
     * Global statistics for a company's DSN declarations.
     */
    public static function statistics(int $companyId): array
    {
        $declarations = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get();

        $byStatus = [];
        foreach ($declarations as $d) {
            $byStatus[$d->status] = ($byStatus[$d->status] ?? 0) + 1;
        }

        return [
            'total' => $declarations->count(),
            'by_status' => $byStatus,
            'latest_period' => $declarations->max('period_month'),
            'earliest_period' => $declarations->min('period_month'),
        ];
    }

    /**
     * Full detail for a single declaration (ops/support view).
     */
    public static function detail(int $declarationId): ?DsnDeclaration
    {
        return DsnDeclaration::withoutGlobalScopes()
            ->with([
                'generatedByUser:id,name',
                'exportedByUser:id,name',
                'submittedByUser:id,name',
                'payrollRun:id,period_start,period_end,status,employee_count',
            ])
            ->find($declarationId);
    }
}
