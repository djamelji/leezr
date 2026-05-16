<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleGate;
use App\Core\Policies\CompanyPolicyResolver;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Facades\DB;

class ComputePayrollUseCase
{
    public function execute(PayrollRun $run, int $actorId): PayrollRun
    {
        // Guard: status must be draft
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw new \DomainException("Cannot compute PayrollRun: status is '{$run->status}', expected 'draft'.");
        }

        // Guard: module enabled
        if (! ModuleGate::isEnabledGlobally('workforce_payroll')) {
            throw new \DomainException('Module workforce_payroll is not enabled for this company.');
        }

        DB::transaction(function () use ($run, $actorId) {
            // Lock the PayrollRun row
            $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            // Delete existing lines (for recompute scenario via draft)
            $run->lines()->delete();

            // Load all active employees for this company
            $employees = Employee::withoutGlobalScopes()
                ->where('company_id', $run->company_id)
                ->where('status', Employee::STATUS_ACTIVE)
                ->get();

            $runAnomalies = [];
            $payFrequencies = [];

            foreach ($employees as $employee) {
                // Find locked TimesheetPeriod covering this payroll period
                $timesheet = TimesheetPeriod::withoutGlobalScopes()
                    ->where('company_id', $run->company_id)
                    ->where('employee_id', $employee->id)
                    ->where('status', TimesheetPeriod::STATUS_LOCKED)
                    ->where('period_start', '<=', $run->period_start)
                    ->where('period_end', '>=', $run->period_end)
                    ->first();

                if (! $timesheet) {
                    $runAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_MISSING_TIMESHEET,
                        'severity' => 'error',
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name,
                        'message' => "No locked timesheet found for {$employee->full_name}",
                    ];
                    continue;
                }

                // Find active CompensationPlan via contract
                $contract = $employee->currentContract;
                $compensation = null;

                if ($contract) {
                    $compensation = CompensationPlan::withoutGlobalScopes()
                        ->where('contract_id', $contract->id)
                        ->where('company_id', $run->company_id)
                        ->activeAt($run->period_end)
                        ->first();
                }

                if (! $compensation) {
                    $runAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_MISSING_COMPENSATION,
                        'severity' => 'error',
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name,
                        'message' => "No active compensation plan found for {$employee->full_name}",
                    ];
                    continue;
                }

                // Track pay frequencies
                $payFrequencies[] = $compensation->pay_frequency;

                // Separate paid/unpaid leave from timesheet lines
                $timesheetLines = $timesheet->lines()->get();
                $paidLeaveHundredths = 0;
                $unpaidLeaveHundredths = 0;
                $totalLeaveMinutes = 0;

                foreach ($timesheetLines as $tsLine) {
                    if (! $tsLine->is_leave_day) {
                        continue;
                    }

                    $snapshot = $tsLine->source_snapshot ?? [];
                    $leaveRequests = $snapshot['leave_requests'] ?? [];

                    foreach ($leaveRequests as $lr) {
                        $leaveTypeCode = $lr['leave_type_code'] ?? null;
                        $daysHundredths = $lr['days_hundredths'] ?? 0;

                        $isPaid = false;
                        if ($leaveTypeCode) {
                            $leaveType = LeaveType::withoutGlobalScopes()
                                ->where('company_id', $run->company_id)
                                ->where('code', $leaveTypeCode)
                                ->first();
                            $isPaid = $leaveType?->is_paid ?? false;
                        }

                        if ($isPaid) {
                            $paidLeaveHundredths += $daysHundredths;
                        } else {
                            $unpaidLeaveHundredths += $daysHundredths;
                        }
                    }

                    $totalLeaveMinutes += $tsLine->leave_minutes ?? 0;
                }

                $totalLeaveHundredths = $paidLeaveHundredths + $unpaidLeaveHundredths;

                // Line anomalies
                $lineAnomalies = [];

                if ($timesheet->anomaly_count > 0) {
                    $lineAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_TIMESHEET_HAS_ERRORS,
                        'severity' => 'warning',
                        'message' => "Timesheet has {$timesheet->anomaly_count} anomalies",
                    ];
                }

                if ($timesheet->total_worked_minutes === 0) {
                    $lineAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_ZERO_WORKED,
                        'severity' => 'warning',
                        'message' => 'Locked timesheet has zero worked minutes',
                    ];
                }

                // Calculate gross_basis_cents
                // MVP: Fixed monthly salary, NOT prorated by worked_minutes
                // Formula: base_salary + overtime_value - unpaid_leave_deduction
                $baseSalaryCents = $compensation->base_salary_cents;
                $overtimeRateBps = $compensation->overtime_rate_bps;
                $weeklyHours = (float) ($contract->weekly_hours ?? 35);

                // Safety anomalies: zero salary / invalid hours
                if ($baseSalaryCents <= 0) {
                    $lineAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_ZERO_BASE_SALARY,
                        'severity' => 'error',
                        'message' => "Base salary is zero for {$employee->first_name} {$employee->last_name}",
                    ];
                }

                if ($weeklyHours <= 0) {
                    $lineAnomalies[] = [
                        'type' => PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS,
                        'severity' => 'error',
                        'message' => "Weekly hours invalid ({$weeklyHours}) for {$employee->first_name} {$employee->last_name}",
                    ];
                }

                // Overtime valorization
                $monthlyHours = $weeklyHours * 52 / 12;
                $hourlyRateCents = $monthlyHours > 0
                    ? (int) round($baseSalaryCents / $monthlyHours)
                    : 0;
                $overtimeHours = $timesheet->total_overtime_minutes / 60;
                $overtimeMultiplier = 1 + ($overtimeRateBps / 10000);
                $overtimeCents = (int) round($overtimeHours * $hourlyRateCents * $overtimeMultiplier);

                // Overtime ventilation (Sprint 6.2 — DSN S21.G00.53 + RGDU)
                // French law: HS 25% = first 8h above legal weekly hours (35h→43h)
                //             HS 50% = hours beyond 43h/week
                //             Daily overtime = from timesheet daily_overtime_minutes
                $dailyOvertimeMinutes = $timesheetLines->sum('daily_overtime_minutes');
                $weeklyOvertimeMinutes = $timesheet->total_overtime_minutes - $dailyOvertimeMinutes;
                $dailyOvertimeHours = $dailyOvertimeMinutes / 60;
                $weeklyOvertimeHours = $weeklyOvertimeMinutes / 60;

                // Weekly overtime split: HS25 (first 8h/week above legal) vs HS50 (beyond)
                $hs25ThresholdHours = 8.0;
                $weeklyHs25Hours = min($weeklyOvertimeHours, $hs25ThresholdHours);
                $weeklyHs50Hours = max(0, $weeklyOvertimeHours - $hs25ThresholdHours);

                // Valorize each overtime tier at the SAME global multiplier
                // (financial total stays identical — ventilation is informational for DSN/RGDU)
                $overtime25Cents = (int) round($weeklyHs25Hours * $hourlyRateCents * $overtimeMultiplier);
                $overtime50Cents = (int) round($weeklyHs50Hours * $hourlyRateCents * $overtimeMultiplier);
                $overtimeDailyCents = (int) round($dailyOvertimeHours * $hourlyRateCents * $overtimeMultiplier);

                // Rounding reconciliation: ensure ventilated sum == total
                $ventilatedSum = $overtime25Cents + $overtime50Cents + $overtimeDailyCents;
                if ($ventilatedSum !== $overtimeCents && $overtimeCents > 0) {
                    $overtime25Cents += ($overtimeCents - $ventilatedSum);
                }

                // Unpaid leave deduction
                // Convention: working days per month ≈ 21.67 (260 / 12)
                $workingDaysPerMonth = 260 / 12;
                $dailyRateCents = (int) round($baseSalaryCents / $workingDaysPerMonth);
                $unpaidLeaveDays = $unpaidLeaveHundredths / 100;
                $unpaidLeaveDeductionCents = (int) round($unpaidLeaveDays * $dailyRateCents);

                $grossBasisCents = $baseSalaryCents + $overtimeCents - $unpaidLeaveDeductionCents;

                // Build gross_breakdown
                $grossBreakdown = [
                    'base_salary_cents' => $baseSalaryCents,
                    'overtime_cents' => $overtimeCents,
                    'unpaid_leave_deduction_cents' => $unpaidLeaveDeductionCents,
                    'gross_basis_cents' => $grossBasisCents,
                    'formula_version' => PayrollLine::FORMULA_VERSION,
                    'hourly_rate_cents' => $hourlyRateCents,
                    'overtime_hours' => round($overtimeHours, 2),
                    'overtime_multiplier' => round($overtimeMultiplier, 4),
                    'unpaid_leave_days' => round($unpaidLeaveDays, 2),
                    'daily_rate_cents' => $dailyRateCents,
                    // Sprint 6.2: Ventilated overtime for DSN/RGDU
                    'overtime_25_cents' => $overtime25Cents,
                    'overtime_50_cents' => $overtime50Cents,
                    'overtime_daily_cents' => $overtimeDailyCents,
                    'overtime_25_hours' => round($weeklyHs25Hours, 2),
                    'overtime_50_hours' => round($weeklyHs50Hours, 2),
                    'overtime_daily_hours' => round($dailyOvertimeHours, 2),
                    'total_hours' => round($monthlyHours + $overtimeHours, 2),
                    'base_hours' => round($monthlyHours, 2),
                ];

                // Build compensation_snapshot
                $compensationSnapshot = [
                    'compensation_plan_id' => $compensation->id,
                    'base_salary_cents' => $baseSalaryCents,
                    'overtime_rate_bps' => $overtimeRateBps,
                    'currency' => $compensation->currency,
                    'pay_frequency' => $compensation->pay_frequency,
                    'effective_from' => $compensation->effective_from?->format('Y-m-d'),
                    'contract_id' => $contract->id,
                    'contract_type' => $contract->contract_type,
                    'work_model_key' => $contract->work_model_key,
                    'weekly_hours' => $contract->weekly_hours,
                ];

                // Build timesheet_snapshot
                $timesheetSnapshot = [
                    'timesheet_period_id' => $timesheet->id,
                    'period_start' => $timesheet->period_start->format('Y-m-d'),
                    'period_end' => $timesheet->period_end->format('Y-m-d'),
                    'status' => $timesheet->status,
                    'total_worked_minutes' => $timesheet->total_worked_minutes,
                    'total_break_minutes' => $timesheet->total_break_minutes,
                    'total_overtime_minutes' => $timesheet->total_overtime_minutes,
                    'total_planned_minutes' => $timesheet->total_planned_minutes,
                    'total_leave_days_hundredths' => $timesheet->total_leave_days_hundredths,
                    'anomaly_count' => $timesheet->anomaly_count,
                    'locked_at' => $timesheet->locked_at?->toIso8601String(),
                    'locked_by' => $timesheet->locked_by,
                ];

                // Create PayrollLine
                $line = PayrollLine::create([
                    'company_id' => $run->company_id,
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'timesheet_period_id' => $timesheet->id,
                    'worked_minutes' => $timesheet->total_worked_minutes,
                    'break_minutes' => $timesheet->total_break_minutes,
                    'daily_overtime_minutes' => $timesheetLines->sum('daily_overtime_minutes'),
                    'weekly_overtime_minutes' => $timesheet->total_overtime_minutes - $timesheetLines->sum('daily_overtime_minutes'),
                    'total_overtime_minutes' => $timesheet->total_overtime_minutes,
                    'planned_minutes' => $timesheet->total_planned_minutes,
                    'leave_days_hundredths' => $totalLeaveHundredths,
                    'paid_leave_days_hundredths' => $paidLeaveHundredths,
                    'unpaid_leave_days_hundredths' => $unpaidLeaveHundredths,
                    'leave_minutes' => $totalLeaveMinutes,
                    'base_salary_cents' => $baseSalaryCents,
                    'overtime_rate_bps' => $overtimeRateBps,
                    'gross_basis_cents' => $grossBasisCents,
                    'gross_breakdown' => $grossBreakdown,
                    'compensation_snapshot' => $compensationSnapshot,
                    'timesheet_snapshot' => $timesheetSnapshot,
                    'export_payload' => null, // Built on export
                    'anomalies' => ! empty($lineAnomalies) ? $lineAnomalies : null,
                ]);
            }

            // Determine pay_frequency_scope
            $uniqueFreqs = array_unique($payFrequencies);
            $payFrequencyScope = count($uniqueFreqs) === 1
                ? $uniqueFreqs[0]
                : (count($uniqueFreqs) > 1 ? 'mixed' : null);

            // Build policy_snapshot — captures actual business rules via CompanyPolicyResolver
            $policyResolver = app(CompanyPolicyResolver::class);
            $workforcePolicies = $policyResolver->snapshot($run->company_id, 'workforce');

            $policySnapshot = [
                'snapshot_version' => 'policy-snapshot-v1',
                'captured_at' => now()->toIso8601String(),
                'resolver_version' => 'CompanyPolicyResolver-v1',
                'workforce_policies' => $workforcePolicies,
                'compute_metadata' => [
                    'employee_count_at_compute' => $employees->count(),
                    'lines_created' => $run->lines()->count(),
                    'anomalies_at_run_level' => count($runAnomalies),
                    'formula_version' => PayrollLine::FORMULA_VERSION,
                ],
            ];

            // Recompute totals + transition
            $run->recomputeTotals();
            $run->anomalies = ! empty($runAnomalies) ? $runAnomalies : null;
            $run->anomaly_count = count($runAnomalies);
            $run->pay_frequency_scope = $payFrequencyScope;
            $run->policy_snapshot = $policySnapshot;
            $run->computed_by = $actorId;
            $run->computed_at = now();
            $run->transitionTo(PayrollRun::STATUS_COMPUTED);
            $run->save();
        });

        $run->refresh();

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payroll_run.computed',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'employee_count' => $run->employee_count,
                    'anomaly_count' => $run->anomaly_count,
                    'description' => "PayrollRun computed: {$run->employee_count} employees, gross {$run->total_gross_cents} cents",
                ],
            ],
        );

        return $run;
    }
}
