<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;

class DetectAnomaliesUseCase
{
    /**
     * Re-scan existing timesheet lines for anomalies.
     * Only works on draft or submitted timesheets.
     * Does not regenerate lines — only updates anomaly data.
     *
     * For a full regeneration, use GenerateTimesheetUseCase.
     * This UseCase is useful for re-evaluating anomalies with updated thresholds
     * without losing manually reviewed data.
     */
    public function execute(TimesheetPeriod $period): TimesheetPeriod
    {
        if (! in_array($period->status, [TimesheetPeriod::STATUS_DRAFT, TimesheetPeriod::STATUS_SUBMITTED], true)) {
            throw new \DomainException(
                "Cannot detect anomalies: timesheet is in status '{$period->status}'. Only draft or submitted timesheets can be re-scanned."
            );
        }

        $lines = $period->lines()->get();
        $totalAnomalies = 0;

        foreach ($lines as $line) {
            $anomalies = $line->anomalies ?? [];

            // Re-evaluate existing anomaly severities from the map
            $updatedAnomalies = [];
            foreach ($anomalies as $anomaly) {
                $type = $anomaly['type'] ?? null;
                if ($type && isset(TimesheetLine::ANOMALY_SEVERITIES[$type])) {
                    $anomaly['severity'] = TimesheetLine::ANOMALY_SEVERITIES[$type];
                }
                $updatedAnomalies[] = $anomaly;
            }

            // Only save if anomalies changed
            if ($updatedAnomalies !== ($line->anomalies ?? [])) {
                // For draft periods, direct update is allowed (I13)
                if ($period->status === TimesheetPeriod::STATUS_DRAFT) {
                    $line->anomalies = ! empty($updatedAnomalies) ? $updatedAnomalies : null;
                    $line->save();
                }
            }

            $totalAnomalies += count($updatedAnomalies);
        }

        $period->anomaly_count = $totalAnomalies;
        $period->save();

        app(AuditLogger::class)->logCompany(
            companyId: $period->company_id,
            action: 'timesheet.anomalies_detected',
            targetType: 'timesheet_period',
            targetId: (string) $period->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.timesheet',
                    'employee_id' => $period->employee_id,
                    'anomaly_count' => $totalAnomalies,
                ],
            ],
        );

        return $period;
    }
}
