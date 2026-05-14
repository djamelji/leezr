<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\PayrollRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PayrollRunReadModel
{
    public static function forCompany(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['computedByUser:id,name', 'validatedByUser:id,name', 'exportedByUser:id,name']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['period_start'])) {
            $query->where('period_start', '>=', $filters['period_start']);
        }

        if (! empty($filters['period_end'])) {
            $query->where('period_end', '<=', $filters['period_end']);
        }

        return $query->orderByDesc('period_start')->paginate($perPage);
    }

    public static function forPeriod(int $companyId, string $periodStart, string $periodEnd): ?PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->forPeriod($periodStart, $periodEnd)
            ->with('lines')
            ->first();
    }

    public static function statistics(int $companyId): array
    {
        $runs = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as count, SUM(total_gross_cents) as total_gross, SUM(employee_count) as total_employees')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'by_status' => $runs->map(fn ($r) => [
                'count' => (int) $r->count,
                'total_gross_cents' => (int) ($r->total_gross ?? 0),
                'total_employees' => (int) ($r->total_employees ?? 0),
            ])->toArray(),
            'total_runs' => $runs->sum('count'),
        ];
    }
}
