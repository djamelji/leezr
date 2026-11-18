<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollRun;
use Illuminate\Support\Collection;

/**
 * ADR-826 — the payroll preparation must never SILENTLY produce a result incompatible with the work model.
 *
 * The engine only knows how to prepare hourly-style pay from a locked hourly timesheet. This guard does not
 * compute anything and invents no rule (no rate, no threshold of its own) : it recognises the situations where
 * the existing calculation would be manifestly wrong for the contract's work model and returns an EXCLUSION
 * anomaly (period-level, error) so HR sees the reason and must justify any validation.
 *
 *  - unknown model (value outside EmploymentContract::WORK_MODELS) → excluded, the contract must be corrected ;
 *  - forfait jours + hourly pay → incoherent combination ;
 *  - forfait jours with overtime on the timesheet → hourly overtime does not apply to a days-based package ;
 *  - forfait heures with overtime → the threshold used is the standard one, not the package volume ;
 *  - part time (contract hours below the effective weekly reference) working beyond its contract →
 *    complementary hours are not supported (they would be unpaid or paid as overtime).
 *
 * Full-time hourly contracts, and part-time / package contracts that stay within their contract, are untouched.
 */
class WorkModelPayrollGuard
{
    /** Type of the anomaly when no locked timesheet covers the period, according to the work model. */
    public function missingTimesheetAnomaly(?EmploymentContract $contract): array
    {
        $model = $contract?->work_model_key;
        if ($model === 'forfait_jours') {
            return ['type' => PayrollRun::ANOMALY_WORK_MODEL_TRACKING_UNSUPPORTED, 'work_model' => $model,
                'message' => 'Days-based package : day tracking is not supported yet ; a locked timesheet is required as the period statement.'];
        }
        if ($contract && ! in_array($model, EmploymentContract::WORK_MODELS, true)) {
            return $this->unknown($model);
        }

        return ['type' => PayrollRun::ANOMALY_MISSING_TIMESHEET, 'work_model' => $model];
    }

    /**
     * @param  Collection<int, object>  $lines  timesheet lines (date, worked_minutes)
     * @return array|null exclusion anomaly (without employee fields) or null when the line can be prepared
     */
    public function assess(?EmploymentContract $contract, ?string $compensationType, int $overtimeMinutes, Collection $lines, ?float $weeklyReference): ?array
    {
        if (! $contract) {
            return null;
        }
        $model = $contract->work_model_key;
        if (! in_array($model, EmploymentContract::WORK_MODELS, true)) {
            return $this->unknown($model);
        }
        if ($model === 'forfait_jours' && $compensationType === 'hourly') {
            return ['type' => PayrollRun::ANOMALY_WORK_MODEL_COMPENSATION_MISMATCH, 'work_model' => $model,
                'message' => 'Days-based package paid by the hour : incoherent contract and compensation.'];
        }
        if ($model === 'forfait_jours' && $overtimeMinutes > 0) {
            return ['type' => PayrollRun::ANOMALY_WORK_MODEL_OVERTIME_NOT_APPLICABLE, 'work_model' => $model, 'overtime_minutes' => $overtimeMinutes,
                'message' => 'Days-based package : hourly overtime does not apply ; the period is not prepared.'];
        }
        if ($model === 'forfait_heures' && $overtimeMinutes > 0) {
            return ['type' => PayrollRun::ANOMALY_WORK_MODEL_OVERTIME_NOT_QUALIFIED, 'work_model' => $model, 'overtime_minutes' => $overtimeMinutes,
                'message' => 'Hours-based package : hours beyond the package are not supported yet ; the period is not prepared.'];
        }
        $contractHours = (float) ($contract->weekly_hours ?? 0);
        if ($weeklyReference !== null && $contractHours > 0 && $contractHours < $weeklyReference) {
            $beyond = $this->minutesBeyondContract($lines, $contractHours);
            if ($beyond > 0 || $overtimeMinutes > 0) {
                return ['type' => PayrollRun::ANOMALY_PART_TIME_HOURS_BEYOND_CONTRACT, 'work_model' => $model,
                    'contract_weekly_hours' => $contractHours, 'minutes_beyond_contract' => $beyond,
                    'message' => 'Part time : hours worked beyond the contract (complementary hours) are not supported yet ; the period is not prepared.'];
            }
        }

        return null;
    }

    /** Sum, per ISO week, of the minutes worked beyond the contract weekly hours. */
    public function minutesBeyondContract(Collection $lines, float $contractHours): int
    {
        return (int) $lines->groupBy(fn ($l) => \Illuminate\Support\Carbon::parse($l->date)->format('o-W'))
            ->sum(fn ($week) => max(0, (int) $week->sum('worked_minutes') - (int) round($contractHours * 60)));
    }

    private function unknown(?string $model): array
    {
        return ['type' => PayrollRun::ANOMALY_WORK_MODEL_UNKNOWN, 'work_model' => $model,
            'message' => "Unknown work model « {$model} » on the current contract : correct the contract before preparing pay."];
    }
}
