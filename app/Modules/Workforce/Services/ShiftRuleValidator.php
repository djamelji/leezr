<?php

namespace App\Modules\Workforce\Services;

use App\Core\Models\Company;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\Shift;
use Carbon\Carbon;

/**
 * Workforce 2.0 LOT F — server-side planning validations driven by the normative
 * WorkforceRuleResolver (work-time dimension). Checks a candidate shift against the
 * employee's EFFECTIVE rules: minimum daily rest between consecutive shifts, daily and
 * weekly maximum working hours, and night-work detection.
 *
 * Design invariants:
 *  - It NEVER invents a legal value: when a rule resolves to `to_complete` (value === null),
 *    the corresponding check is skipped rather than blocking — an undocumented dimension
 *    must not fabricate a constraint.
 *  - Hard legal limits (legal_min rest, legal_max hours) throw DomainException → HTTP 422.
 *  - Night work is informational (advisory flag), never a block.
 *  - Aggregations use the employee's non-cancelled shifts and may exclude the current shift
 *    (update) or a whole batch (publish) to avoid counting a row against itself.
 */
class ShiftRuleValidator
{
    public function __construct(private WorkforceRuleResolver $resolver) {}

    /**
     * Validate a candidate shift. Throws DomainException on a legal violation.
     *
     * @param  array<int>  $excludeIds  shift ids to exclude from aggregation (e.g. publish batch)
     * @return array{night_work: bool, worked_minutes: int, daily_minutes: int, weekly_minutes: int}
     */
    public function validate(
        int $companyId,
        int $employeeId,
        ?EmploymentContract $contract,
        Carbon $startAt,
        Carbon $endAt,
        string $date,
        int $breakMinutes = 0,
        ?int $excludeShiftId = null,
        array $excludeIds = [],
    ): array {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! $company) {
            throw new \DomainException('Company not found for rule resolution.');
        }

        $rules = $this->resolver->resolveWorkTimeRules($company, $startAt, $contract);

        $workedMinutes = max(0, $startAt->diffInMinutes($endAt) - max(0, $breakMinutes));

        $this->checkDailyRest($rules, $companyId, $employeeId, $startAt, $endAt, $date, $excludeShiftId, $excludeIds);
        $dailyMinutes = $this->checkDailyMax($rules, $companyId, $employeeId, $date, $workedMinutes, $excludeShiftId, $excludeIds);
        $weeklyMinutes = $this->checkWeeklyMax($rules, $companyId, $employeeId, $startAt, $workedMinutes, $excludeShiftId, $excludeIds);

        return [
            'night_work' => $this->isNightWork($rules, $startAt, $endAt),
            'worked_minutes' => $workedMinutes,
            'daily_minutes' => $dailyMinutes,
            'weekly_minutes' => $weeklyMinutes,
        ];
    }

    /** A numeric rule value in hours, or null when undocumented (to_complete). */
    private function hours(array $rules, string $key): ?float
    {
        $v = $rules[$key]['value'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Minimum daily rest between working DAYS. The legal daily rest (e.g. 11h) separates the end of
     * one working day from the start of the next — it does NOT apply between split shifts of the same
     * day, so neighbours are restricted to a different calendar date.
     */
    private function checkDailyRest(array $rules, int $companyId, int $employeeId, Carbon $startAt, Carbon $endAt, string $date, ?int $excludeShiftId, array $excludeIds): void
    {
        $restHours = $this->hours($rules, 'daily_rest_min_hours');
        if ($restHours === null) {
            return; // undocumented → do not fabricate a constraint
        }
        $restMinutes = (int) round($restHours * 60);

        $base = $this->neighbourQuery($companyId, $employeeId, $excludeShiftId, $excludeIds);

        // Closest shift on an EARLIER day ending before this one starts.
        $prev = (clone $base)->whereDate('date', '<', $date)->where('end_at', '<=', $startAt)->orderByDesc('end_at')->first();
        if ($prev && $prev->end_at->diffInMinutes($startAt) < $restMinutes) {
            throw new \DomainException("Insufficient daily rest: at least {$restHours}h required between working days.");
        }

        // Closest shift on a LATER day starting after this one ends.
        $next = (clone $base)->whereDate('date', '>', $date)->where('start_at', '>=', $endAt)->orderBy('start_at')->first();
        if ($next && $endAt->diffInMinutes($next->start_at) < $restMinutes) {
            throw new \DomainException("Insufficient daily rest: at least {$restHours}h required between working days.");
        }
    }

    /** Daily worked hours must not exceed the legal/conventional daily ceiling. */
    private function checkDailyMax(array $rules, int $companyId, int $employeeId, string $date, int $candidateMinutes, ?int $excludeShiftId, array $excludeIds): int
    {
        $existing = $this->neighbourQuery($companyId, $employeeId, $excludeShiftId, $excludeIds)
            ->whereDate('date', $date)
            ->get()
            ->sum(fn (Shift $s) => $s->plannedWorkMinutes());

        $total = $existing + $candidateMinutes;

        $maxHours = $this->hours($rules, 'daily_hours_max');
        if ($maxHours !== null && $total > (int) round($maxHours * 60)) {
            throw new \DomainException("Daily working time exceeds the {$maxHours}h maximum for this day.");
        }

        return $total;
    }

    /** Weekly worked hours (ISO week, Monday→Sunday) must not exceed the weekly ceiling. */
    private function checkWeeklyMax(array $rules, int $companyId, int $employeeId, Carbon $startAt, int $candidateMinutes, ?int $excludeShiftId, array $excludeIds): int
    {
        $weekStart = $startAt->copy()->startOfWeek();
        $weekEnd = $startAt->copy()->endOfWeek();

        $existing = $this->neighbourQuery($companyId, $employeeId, $excludeShiftId, $excludeIds)
            ->whereBetween('start_at', [$weekStart, $weekEnd])
            ->get()
            ->sum(fn (Shift $s) => $s->plannedWorkMinutes());

        $total = $existing + $candidateMinutes;

        $maxHours = $this->hours($rules, 'weekly_hours_max');
        if ($maxHours !== null && $total > (int) round($maxHours * 60)) {
            throw new \DomainException("Weekly working time exceeds the {$maxHours}h maximum for this week.");
        }

        return $total;
    }

    /** Non-cancelled shifts of the employee, excluding self and/or a batch. */
    private function neighbourQuery(int $companyId, int $employeeId, ?int $excludeShiftId, array $excludeIds)
    {
        $q = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', '!=', Shift::STATUS_CANCELLED);

        if ($excludeShiftId !== null) {
            $q->where('id', '!=', $excludeShiftId);
        }
        if (! empty($excludeIds)) {
            $q->whereNotIn('id', $excludeIds);
        }

        return $q;
    }

    /** True when the shift overlaps the configured night-work window (handles midnight crossing). */
    private function isNightWork(array $rules, Carbon $startAt, Carbon $endAt): bool
    {
        $start = $rules['night_work_start']['value'] ?? null;
        $end = $rules['night_work_end']['value'] ?? null;
        if (! is_string($start) || ! is_string($end) || ! str_contains($start, ':') || ! str_contains($end, ':')) {
            return false;
        }

        // Build the night window on the shift's start date; if it crosses midnight, extend to the next day.
        $day = $startAt->copy()->startOfDay();
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        $winStart = $day->copy()->setTime($sh, $sm);
        $winEnd = $day->copy()->setTime($eh, $em);
        if ($winEnd->lte($winStart)) {
            $winEnd->addDay(); // e.g. 21:00 → 06:00 next day
        }

        // Overlap test against both the current and the previous night window (for early-morning shifts).
        foreach ([0, -1] as $offset) {
            $ws = $winStart->copy()->addDays($offset);
            $we = $winEnd->copy()->addDays($offset);
            if ($startAt->lt($we) && $endAt->gt($ws)) {
                return true;
            }
        }

        return false;
    }
}
