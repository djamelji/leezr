<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Core\Workforce\Services\AccountingDayAllocator;
use App\Core\Policies\CompanyPolicyResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class GenerateTimesheetUseCase
{
    public function execute(
        int $companyId,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
    ): TimesheetPeriod {
        $start = Carbon::parse($periodStart);
        $end = Carbon::parse($periodEnd);

        if ($end->lte($start)) {
            throw new \DomainException('Period end must be after period start.');
        }

        // I10: Employee not terminated
        $employee = Employee::withoutGlobalScopes()
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->first();

        if (! $employee) {
            throw new \DomainException('Employee not found in this company.');
        }

        if ($employee->status === Employee::STATUS_TERMINATED) {
            throw new \DomainException('Cannot generate timesheet for a terminated employee.');
        }

        return DB::transaction(function () use ($companyId, $employeeId, $start, $end, $employee) {
            // Check for existing period (regenerate if draft, fail if other status)
            $existing = TimesheetPeriod::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->forPeriod($start->toDateString(), $end->toDateString())
                ->first();

            if ($existing) {
                if ($existing->status !== TimesheetPeriod::STATUS_DRAFT) {
                    throw new \DomainException(
                        "A timesheet for this period already exists in status '{$existing->status}'. Only draft timesheets can be regenerated."
                    );
                }

                // Delete existing lines for regeneration
                $existing->lines()->delete();
                $period = $existing;
            } else {
                // I2/I11: No overlapping periods
                $hasOverlap = TimesheetPeriod::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employeeId)
                    ->overlapping($start->toDateString(), $end->toDateString())
                    ->exists();

                if ($hasOverlap) {
                    throw new \DomainException('An overlapping timesheet period already exists for this employee.');
                }

                $period = TimesheetPeriod::create([
                    'company_id' => $companyId,
                    'employee_id' => $employeeId,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'status' => TimesheetPeriod::STATUS_DRAFT,
                ]);
            }

            // Resolve policies for overtime/anomaly thresholds
            $policyResolver = app(CompanyPolicyResolver::class);
            $contract = $employee->currentContract;
            $weeklyHours = $contract?->weekly_hours ?? 35.00;
            $dailyThreshold = (int) (($policyResolver->get($companyId, 'workforce', 'overtime_daily_threshold') ?? ($weeklyHours / 5)) * 60);
            $minimumBreak = (int) ($policyResolver->get($companyId, 'workforce', 'minimum_break_minutes') ?? 20);
            $arrivalTolerance = (int) ($policyResolver->get($companyId, 'workforce', 'arrival_tolerance_minutes') ?? 15);
            $standardDayMinutes = (int) ($policyResolver->get($companyId, 'workforce', 'standard_day_minutes') ?? ($weeklyHours / 5 * 60));

            // Load source data for the period
            $allocator = app(AccountingDayAllocator::class);

            $completedEntries = TimeEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->completed()
                ->with('breaks')
                ->get();

            // CORR #11: Also load non-completed entries for anomaly detection only
            $nonCompletedEntries = TimeEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->where('status', '!=', TimeEntry::STATUS_COMPLETED)
                ->whereIn('status', [TimeEntry::STATUS_WORKING, TimeEntry::STATUS_ON_BREAK])
                ->get();

            $shifts = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->notCancelled()
                ->get();

            $leaveRequests = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                ->where('date_from', '<=', $end->toDateString())
                ->where('date_to', '>=', $start->toDateString())
                ->with('leaveType')
                ->get();

            // Pre-compute allocated minutes per day from completed entries
            $allocatedByDate = [];
            foreach ($completedEntries as $entry) {
                $allocated = $allocator->allocate($entry);
                foreach ($allocated as $alloc) {
                    $allocatedByDate[$alloc['date']][] = [
                        'entry' => $entry,
                        'minutes' => $alloc['minutes'],
                    ];
                }
            }

            // Generate lines for each day in the period
            $dates = CarbonPeriod::create($start, $end);

            foreach ($dates as $dateCarbon) {
                $dateString = $dateCarbon->toDateString();
                $anomalies = [];

                // --- TimeEntry data (completed only for worked/break) ---
                $dayAllocations = $allocatedByDate[$dateString] ?? [];
                $workedMinutes = 0;
                $breakMinutes = 0;
                $timeEntrySnapshots = [];

                foreach ($dayAllocations as $alloc) {
                    $entry = $alloc['entry'];
                    $workedMinutes += $alloc['minutes'];
                    $breakMinutes += $entry->total_break_minutes ?? 0;

                    $timeEntrySnapshots[] = [
                        'id' => $entry->id,
                        'clock_in' => $entry->clock_in?->toIso8601String(),
                        'clock_out' => $entry->clock_out?->toIso8601String(),
                        'worked_minutes' => $entry->total_worked_minutes,
                        'break_minutes' => $entry->total_break_minutes,
                    ];
                }

                // Avoid double-counting break minutes from multi-day entries
                // Break minutes are already included in the entry-level total
                // For allocated entries, we approximate: proportional break
                if (count($dayAllocations) > 0) {
                    $totalEntryWorked = collect($dayAllocations)->sum(fn ($a) => $a['entry']->total_worked_minutes ?: 0);
                    if ($totalEntryWorked > 0) {
                        $totalEntryBreak = collect($dayAllocations)->sum(fn ($a) => $a['entry']->total_break_minutes ?: 0);
                        $breakMinutes = (int) round($totalEntryBreak * ($workedMinutes / max(1, $totalEntryWorked)));
                    } else {
                        $breakMinutes = 0;
                    }
                }

                // CORR #11: Non-completed entries → anomaly only
                $nonCompletedForDay = $nonCompletedEntries->filter(
                    fn ($e) => $e->date->toDateString() === $dateString
                );
                foreach ($nonCompletedForDay as $ncEntry) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_MISSING_CLOCK_OUT,
                        'severity' => TimesheetLine::SEVERITY_WARNING,
                        'details' => [
                            'time_entry_id' => $ncEntry->id,
                            'clock_in' => $ncEntry->clock_in?->toIso8601String(),
                            'status' => $ncEntry->status,
                        ],
                    ];
                }

                // --- Shift data ---
                $dayShifts = $shifts->filter(fn ($s) => $s->date->toDateString() === $dateString);
                $plannedMinutes = $dayShifts->sum(fn ($s) => $s->plannedWorkMinutes());
                $shiftSnapshots = $dayShifts->map(fn ($s) => [
                    'id' => $s->id,
                    'start_at' => $s->start_at->toIso8601String(),
                    'end_at' => $s->end_at->toIso8601String(),
                    'planned_minutes' => $s->plannedWorkMinutes(),
                    'template_name' => $s->template_snapshot['template_name'] ?? null,
                ])->values()->toArray();

                // --- Leave data ---
                $dayLeaves = $leaveRequests->filter(
                    fn ($lr) => $lr->date_from->lte($dateCarbon) && $lr->date_to->gte($dateCarbon)
                );
                $isLeaveDay = $dayLeaves->isNotEmpty();
                $leaveTypeCode = $dayLeaves->first()?->leaveType?->code;
                $leaveMinutes = 0;
                $leaveSnapshots = [];

                foreach ($dayLeaves as $lr) {
                    $leaveDays = $lr->date_from->diffInDays($lr->date_to) + 1;
                    $dailyHundredths = $leaveDays > 0 ? (int) round($lr->days_count_hundredths / $leaveDays) : 0;
                    $leaveMinutes += (int) round($standardDayMinutes * ($dailyHundredths / 100));

                    $leaveSnapshots[] = [
                        'id' => $lr->id,
                        'type_code' => $lr->leaveType?->code,
                        'days_hundredths' => $dailyHundredths,
                    ];
                }

                // --- Anomaly detection ---

                // Missing time entry: shift exists but no completed entry
                if ($dayShifts->isNotEmpty() && empty($dayAllocations) && ! $isLeaveDay) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_MISSING_TIME_ENTRY,
                        'severity' => TimesheetLine::SEVERITY_INFO,
                        'details' => [
                            'shift_ids' => $dayShifts->pluck('id')->toArray(),
                            'planned_minutes' => $plannedMinutes,
                        ],
                    ];
                }

                // Unplanned work: time entry without shift
                if (! empty($dayAllocations) && $dayShifts->isEmpty()) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_UNPLANNED_WORK,
                        'severity' => TimesheetLine::SEVERITY_INFO,
                        'details' => [
                            'time_entry_ids' => collect($dayAllocations)->map(fn ($a) => $a['entry']->id)->toArray(),
                            'worked_minutes' => $workedMinutes,
                        ],
                    ];
                }

                // Leave conflict: worked on leave day
                if ($isLeaveDay && $workedMinutes > 0) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_LEAVE_CONFLICT,
                        'severity' => TimesheetLine::SEVERITY_ERROR,
                        'details' => [
                            'time_entry_ids' => collect($dayAllocations)->map(fn ($a) => $a['entry']->id)->toArray(),
                            'leave_request_ids' => $dayLeaves->pluck('id')->toArray(),
                            'worked_minutes' => $workedMinutes,
                        ],
                    ];
                }

                // Daily overtime
                $dailyOvertimeMinutes = max(0, $workedMinutes - $dailyThreshold);
                if ($dailyOvertimeMinutes > 0) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_OVERTIME_DAILY,
                        'severity' => TimesheetLine::SEVERITY_WARNING,
                        'details' => [
                            'worked' => $workedMinutes,
                            'threshold' => $dailyThreshold,
                            'excess' => $dailyOvertimeMinutes,
                        ],
                    ];
                }

                // Insufficient break
                if ($workedMinutes > 360 && $breakMinutes < $minimumBreak) {
                    $anomalies[] = [
                        'type' => TimesheetLine::ANOMALY_INSUFFICIENT_BREAK,
                        'severity' => TimesheetLine::SEVERITY_WARNING,
                        'details' => [
                            'break_actual' => $breakMinutes,
                            'break_required' => $minimumBreak,
                            'worked' => $workedMinutes,
                        ],
                    ];
                }

                // Late arrival / early departure (compare first entry vs first shift)
                if ($dayShifts->isNotEmpty() && ! empty($dayAllocations)) {
                    $firstShift = $dayShifts->sortBy('start_at')->first();
                    $firstEntry = collect($dayAllocations)->map(fn ($a) => $a['entry'])->sortBy('clock_in')->first();

                    if ($firstEntry?->clock_in && $firstShift) {
                        $delay = $firstEntry->clock_in->diffInMinutes($firstShift->start_at, false);
                        if ($delay < -$arrivalTolerance) {
                            $anomalies[] = [
                                'type' => TimesheetLine::ANOMALY_LATE_ARRIVAL,
                                'severity' => TimesheetLine::SEVERITY_INFO,
                                'details' => [
                                    'clock_in' => $firstEntry->clock_in->toIso8601String(),
                                    'shift_start' => $firstShift->start_at->toIso8601String(),
                                    'delay_minutes' => abs($delay),
                                ],
                            ];
                        }
                    }

                    $lastShift = $dayShifts->sortByDesc('end_at')->first();
                    $lastEntry = collect($dayAllocations)->map(fn ($a) => $a['entry'])->sortByDesc('clock_out')->first();

                    if ($lastEntry?->clock_out && $lastShift) {
                        $early = $lastShift->end_at->diffInMinutes($lastEntry->clock_out, false);
                        if ($early < -$arrivalTolerance) {
                            $anomalies[] = [
                                'type' => TimesheetLine::ANOMALY_EARLY_DEPARTURE,
                                'severity' => TimesheetLine::SEVERITY_INFO,
                                'details' => [
                                    'clock_out' => $lastEntry->clock_out->toIso8601String(),
                                    'shift_end' => $lastShift->end_at->toIso8601String(),
                                    'early_minutes' => abs($early),
                                ],
                            ];
                        }
                    }
                }

                // --- Create line ---
                TimesheetLine::create([
                    'company_id' => $companyId,
                    'timesheet_period_id' => $period->id,
                    'employee_id' => $employeeId,
                    'date' => $dateString,
                    'worked_minutes' => $workedMinutes,
                    'break_minutes' => $breakMinutes,
                    'daily_overtime_minutes' => $dailyOvertimeMinutes,
                    'planned_minutes' => $plannedMinutes,
                    'leave_minutes' => $leaveMinutes,
                    'leave_type_code' => $leaveTypeCode,
                    'is_leave_day' => $isLeaveDay,
                    'is_rest_day' => false,
                    'anomalies' => ! empty($anomalies) ? $anomalies : null,
                    'source_snapshot' => [
                        'time_entries' => $timeEntrySnapshots,
                        'shifts' => $shiftSnapshots,
                        'leave_requests' => $leaveSnapshots,
                    ],
                ]);
            }

            // Weekly overtime detection
            $weeklyWorked = [];
            $allLines = $period->lines()->get();
            foreach ($allLines as $line) {
                $weekNum = $line->date->isoWeek();
                $weeklyWorked[$weekNum] = ($weeklyWorked[$weekNum] ?? 0) + $line->worked_minutes;
            }
            $weeklyThreshold = (int) ($weeklyHours * 60);
            foreach ($weeklyWorked as $weekNum => $total) {
                if ($total > $weeklyThreshold) {
                    // Add weekly overtime anomaly to the last day of that week in the period
                    $lastDayOfWeek = $allLines
                        ->filter(fn ($l) => $l->date->isoWeek() === $weekNum)
                        ->sortByDesc('date')
                        ->first();

                    if ($lastDayOfWeek) {
                        $currentAnomalies = $lastDayOfWeek->anomalies ?? [];
                        $currentAnomalies[] = [
                            'type' => TimesheetLine::ANOMALY_OVERTIME_WEEKLY,
                            'severity' => TimesheetLine::SEVERITY_WARNING,
                            'details' => [
                                'actual_weekly' => $total,
                                'threshold' => $weeklyThreshold,
                                'excess' => $total - $weeklyThreshold,
                                'week_number' => $weekNum,
                            ],
                        ];
                        // Direct update since period is draft
                        $lastDayOfWeek->anomalies = $currentAnomalies;
                        $lastDayOfWeek->save();
                    }
                }
            }

            // Recompute totals
            $period->recomputeTotals();
            $period->save();

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'timesheet.generated',
                targetType: 'timesheet_period',
                targetId: (string) $period->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $employeeId,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'line_count' => $period->lines()->count(),
                        'anomaly_count' => $period->anomaly_count,
                    ],
                ],
            );

            return $period;
        });
    }
}
