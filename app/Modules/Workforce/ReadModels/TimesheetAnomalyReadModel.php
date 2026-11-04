<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;

class TimesheetAnomalyReadModel
{
    /**
     * Get anomalies for a period grouped by type.
     */
    public static function forPeriod(int $timesheetPeriodId): array
    {
        $lines = TimesheetLine::withoutGlobalScopes()
            ->where('timesheet_period_id', $timesheetPeriodId)
            ->whereNotNull('anomalies')
            ->orderBy('date')
            ->get();

        $byType = [];

        foreach ($lines as $line) {
            foreach ($line->anomalies ?? [] as $anomaly) {
                $type = $anomaly['type'] ?? 'unknown';
                // P0 (ADR-701) : les heures sup ne sont pas des anomalies techniques — exclues du groupement
                // (y compris pour les feuilles historiques générées avant P0, sans muter leurs données).
                if (in_array($type, TimesheetLine::OVERTIME_ANOMALY_TYPES, true)) {
                    continue;
                }
                $byType[$type][] = [
                    'date' => $line->date->toDateString(),
                    'severity' => $anomaly['severity'] ?? null,
                    'details' => $anomaly['details'] ?? [],
                ];
            }
        }

        return $byType;
    }

    /**
     * Dashboard view: anomaly statistics for a company period.
     */
    public static function dashboard(int $companyId, string $periodStart, string $periodEnd): array
    {
        $periodIds = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->overlapping($periodStart, $periodEnd)
            ->pluck('id');

        $lines = TimesheetLine::withoutGlobalScopes()
            ->whereIn('timesheet_period_id', $periodIds)
            ->whereNotNull('anomalies')
            ->get();

        $totalAnomalies = 0;
        $byType = [];
        $employeesWithAnomalies = [];

        foreach ($lines as $line) {
            foreach ($line->anomalies ?? [] as $anomaly) {
                $type = $anomaly['type'] ?? 'unknown';
                // P0 (ADR-701) : heures sup exclues du compte d'anomalies techniques du dashboard.
                if (in_array($type, TimesheetLine::OVERTIME_ANOMALY_TYPES, true)) {
                    continue;
                }
                $totalAnomalies++;
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                $employeesWithAnomalies[$line->employee_id] = true;
            }
        }

        arsort($byType);

        return [
            'total_anomalies' => $totalAnomalies,
            'by_type' => $byType,
            'employees_with_anomalies' => count($employeesWithAnomalies),
            'most_common_type' => ! empty($byType) ? array_key_first($byType) : null,
        ];
    }
}
