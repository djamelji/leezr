<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollRun;

class ValidatePayrollUseCase
{
    public function execute(PayrollRun $run, int $actorId, ?string $validationNote = null): PayrollRun
    {
        // Guard: status must be computed
        if ($run->status !== PayrollRun::STATUS_COMPUTED) {
            throw new \DomainException("Cannot validate PayrollRun: status is '{$run->status}', expected 'computed'.");
        }

        // Guard I14: cannot validate if employee_count = 0
        if ($run->employee_count === 0) {
            throw new \DomainException('Cannot validate PayrollRun with zero employees.');
        }

        // Guard: error anomalies require validation_note
        if ($run->hasErrorAnomalies() && empty($validationNote)) {
            throw new \DomainException('PayrollRun has error-level anomalies. A validation_note is required to proceed.');
        }

        // Guard: all lines must have calculations (Phase 3 — ADR-496)
        $linesWithoutCalc = $run->lines()
            ->whereDoesntHave('calculation')
            ->count();

        if ($linesWithoutCalc > 0) {
            throw new \DomainException(
                "Cannot validate: {$linesWithoutCalc} payroll line(s) have no calculation. Run ComputePayrollCalculations first."
            );
        }

        // Guard: no blocking anomalies in calculations (Phase 3 — ADR-496)
        $blockingCount = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->whereNotNull('blocking_anomalies')
            ->count();

        if ($blockingCount > 0) {
            throw new \DomainException(
                "Cannot validate: {$blockingCount} calculation(s) have blocking anomalies."
            );
        }

        $run->transitionTo(PayrollRun::STATUS_VALIDATED);
        $run->validated_by = $actorId;
        $run->validated_at = now();
        $run->validation_note = $validationNote;
        $run->save();

        // Mark all calculations as validated (Phase 3 — ADR-496)
        PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->update(['status' => PayrollCalculation::STATUS_VALIDATED]);

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payroll_run.validated',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'has_validation_note' => ! empty($validationNote),
                    'anomaly_count' => $run->anomaly_count,
                    'employee_count' => $run->employee_count,
                ],
            ],
        );

        return $run;
    }
}
