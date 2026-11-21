<?php

namespace App\Modules\Workforce\Exports;

use App\Core\Models\Company;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-844 (Leezr 4.2 M7, FUN-06) — time, planning and absence exports by period. Same principles as the
 * employees export (W-G2, ADR-642) : CSV streamed with a UTF-8 BOM, bounded, company-scoped, visibility scope
 * of the domain (WorkforceAccess), no sensitive data (no e-mail, phone, bank or national identifiers).
 *
 * Every figure is READ from its source — nothing is recalculated :
 *  - entries  : clock-ins (workforce_time_entries) — worked / break minutes as recorded by the clock ;
 *  - breaks   : the breaks of those clock-ins (workforce_time_entry_breaks) ;
 *  - daily    : planned vs worked per day from the timesheets (workforce_timesheet_lines) with the status of their
 *               period — « validated » = approved or locked ; overtime = `daily_overtime_minutes` identified by the
 *               timesheet engine ; days without a generated timesheet are not invented ;
 *  - planning : shifts (workforce_shifts) with their site and own timezone ; duration = end − start (span of the shift) ;
 *  - leaves   : leave requests overlapping the period with their status (pending / approved / …), days and hours as
 *               recorded at request time (no entitlement recalculated).
 * Clock times are given in the company timezone (market), shift times in the shift timezone.
 */
class TimeExportService
{
    public const DATASETS = ['entries', 'breaks', 'daily', 'planning', 'leaves'];

    /** permission domain (WorkforceAccess) of each dataset */
    public const DOMAIN = ['entries' => 'time', 'breaks' => 'time', 'daily' => 'time', 'planning' => 'planning', 'leaves' => 'leave'];

    /** module that owns each dataset (inactive module → dataset unavailable) */
    public const MODULE = ['entries' => 'workforce', 'breaks' => 'workforce', 'daily' => 'workforce', 'planning' => 'workforce_planning', 'leaves' => 'workforce_leave'];

    public const COLUMNS = [
        'entries' => ['date', 'employee_number', 'last_name', 'first_name', 'department', 'clock_in', 'clock_out', 'break_minutes', 'worked_minutes', 'worked_hhmm', 'status', 'source'],
        'breaks' => ['date', 'employee_number', 'last_name', 'first_name', 'start', 'end', 'type', 'duration_minutes'],
        'daily' => ['date', 'employee_number', 'last_name', 'first_name', 'planned_minutes', 'worked_minutes', 'difference_minutes', 'break_minutes', 'overtime_identified_minutes', 'leave_minutes', 'leave_type', 'rest_day', 'anomalies', 'timesheet_status', 'validated'],
        'planning' => ['date', 'employee_number', 'last_name', 'first_name', 'site', 'start', 'end', 'timezone', 'shift_minutes', 'status'],
        'leaves' => ['employee_number', 'last_name', 'first_name', 'leave_type', 'from', 'to', 'start_time', 'end_time', 'days', 'hours', 'status', 'reviewed_at'],
    ];

    public const MAX_ROWS = 10_000;

    public const LARGE_ROWS = 2_000;

    public const MAX_DAYS = 366;

    public const PREVIEW_ROWS = 15;

    /**
     * @param  array{from: string, to: string, employee_ids?: int[], work_location_id?: ?int}  $filters
     * @param  array<string, ?int[]>  $scopes  dataset domain → visible employee ids (null = everyone)
     */
    public function __construct(private readonly Company $company, private readonly array $filters, private readonly array $scopes) {}

    public function timezone(): string
    {
        return $this->company->market?->timezone ?: config('app.timezone', 'UTC');
    }

    /** @return array{count:int, columns:string[], rows:array, warnings:string[]} */
    public function preview(string $dataset): array
    {
        $count = (clone $this->query($dataset))->count();
        $rows = [];
        foreach ($this->query($dataset)->limit(self::PREVIEW_ROWS)->get() as $model) {
            $rows[] = array_combine(self::COLUMNS[$dataset], $this->row($dataset, $model));
        }
        $warnings = [];
        if ($count === 0) {
            $warnings[] = 'empty';
        }
        if ($count > self::LARGE_ROWS) {
            $warnings[] = $count > self::MAX_ROWS ? 'too_large' : 'large';
        }
        if ($dataset === 'daily' && $count > 0) {
            $unvalidated = (clone $this->query($dataset))->whereHas('timesheetPeriod', fn ($q) => $q->whereNotIn('status', [TimesheetPeriod::STATUS_APPROVED, TimesheetPeriod::STATUS_LOCKED]))->count();
            if ($unvalidated > 0) {
                $warnings[] = 'unvalidated:'.$unvalidated;
            }
        }
        if ($dataset === 'leaves' && (clone $this->query($dataset))->where('status', LeaveRequest::STATUS_PENDING)->exists()) {
            $warnings[] = 'pending_leaves';
        }
        if ($dataset === 'entries' && (clone $this->query($dataset))->whereNull('clock_out')->exists()) {
            $warnings[] = 'open_entries';
        }

        return ['count' => $count, 'columns' => self::COLUMNS[$dataset], 'rows' => $rows, 'warnings' => $warnings];
    }

    public function count(string $dataset): int
    {
        return (clone $this->query($dataset))->count();
    }

    public function stream(string $dataset, callable $header): StreamedResponse
    {
        $query = $this->query($dataset)->limit(self::MAX_ROWS);
        $columns = array_map($header, self::COLUMNS[$dataset]);
        $name = sprintf('%s_%s_%s.csv', $dataset, $this->filters['from'], $this->filters['to']);

        return new StreamedResponse(function () use ($query, $dataset, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
            fputcsv($out, $columns, ';');
            $query->chunk(500, function ($models) use ($out, $dataset) {
                foreach ($models as $m) {
                    fputcsv($out, $this->row($dataset, $m), ';');
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ── queries ───────────────────────────────────────────────────────────────────────────────────────────

    private function query(string $dataset): Builder
    {
        $from = $this->filters['from'];
        $to = $this->filters['to'];
        $with = ['employee:id,employee_number,first_name,last_name,department_id', 'employee.department:id,name'];
        $q = match ($dataset) {
            'entries' => TimeEntry::query()->with($with)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('clock_in'),
            'breaks' => TimeEntryBreak::query()->join('workforce_time_entries as te', 'te.id', '=', 'workforce_time_entry_breaks.time_entry_id')
                ->join('workforce_employees as emp', 'emp.id', '=', 'te.employee_id')
                ->where('workforce_time_entry_breaks.company_id', $this->company->id)
                ->where('te.company_id', $this->company->id)
                ->whereBetween('te.date', [$from, $to])
                ->select('workforce_time_entry_breaks.*', 'te.date as entry_date', 'te.employee_id', 'emp.employee_number', 'emp.first_name', 'emp.last_name')
                ->orderBy('te.date')->orderBy('workforce_time_entry_breaks.start_at'),
            'daily' => TimesheetLine::query()->with(array_merge($with, ['timesheetPeriod:id,status']))->whereBetween('date', [$from, $to])->orderBy('date'),
            'planning' => Shift::query()->with(array_merge($with, ['workLocation:id,name']))->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('start_at'),
            'leaves' => LeaveRequest::query()->with(array_merge($with, ['leaveType:id,name,code']))
                ->where('status', '!=', LeaveRequest::STATUS_DRAFT)
                ->where('date_from', '<=', $to)->where('date_to', '>=', $from)->orderBy('date_from'),
        };

        $employeeColumn = $dataset === 'breaks' ? 'te.employee_id' : 'employee_id';
        if ($dataset !== 'breaks') {
            $q->where($q->getModel()->getTable().'.company_id', $this->company->id); // defence in depth (CompanyScope also applies)
        }
        $scope = $this->scopes[self::DOMAIN[$dataset]] ?? null;
        if ($scope !== null) {
            $q->whereIn($employeeColumn, $scope ?: [0]);
        }
        if (! empty($this->filters['employee_ids'])) {
            $q->whereIn($employeeColumn, $this->filters['employee_ids']);
        }
        if ($dataset === 'planning' && ! empty($this->filters['work_location_id'])) {
            $q->where('work_location_id', $this->filters['work_location_id']);
        }

        return $q;
    }

    // ── rows ──────────────────────────────────────────────────────────────────────────────────────────────

    private function row(string $dataset, $m): array
    {
        $tz = $this->timezone();
        $local = fn (?Carbon $at, ?string $zone = null) => $at ? $at->copy()->setTimezone($zone ?: $tz)->format('Y-m-d H:i') : '';
        $person = function ($employee) {
            return [$employee?->employee_number ?? '', $employee?->last_name ?? '', $employee?->first_name ?? ''];
        };

        return match ($dataset) {
            'entries' => [
                $m->date?->toDateString(), ...$person($m->employee), $m->employee?->department?->name ?? '',
                $local($m->clock_in), $local($m->clock_out), (int) $m->total_break_minutes, (int) $m->total_worked_minutes,
                self::hhmm((int) $m->total_worked_minutes), $this->label('entry', $m->status), $m->source ?? '',
            ],
            'breaks' => [Carbon::parse($m->entry_date)->toDateString(), ...$person($m), $local($m->start_at ? Carbon::parse($m->start_at) : null),
                $local($m->end_at ? Carbon::parse($m->end_at) : null), $this->label('break', $m->type), (int) $m->duration_minutes],
            'daily' => [
                $m->date?->toDateString(), ...$person($m->employee), (int) $m->planned_minutes, (int) $m->worked_minutes,
                (int) $m->worked_minutes - (int) $m->planned_minutes, (int) $m->break_minutes, (int) $m->daily_overtime_minutes,
                (int) $m->leave_minutes, $m->leave_type_code ?? '', $this->label('bool', $m->is_rest_day ? 'yes' : 'no'),
                is_array($m->anomalies) ? count($m->anomalies) : 0, $this->label('timesheet', $m->timesheetPeriod?->status),
                $this->label('bool', in_array($m->timesheetPeriod?->status, [TimesheetPeriod::STATUS_APPROVED, TimesheetPeriod::STATUS_LOCKED], true) ? 'yes' : 'no'),
            ],
            'planning' => [
                $m->date?->toDateString(), ...$person($m->employee), $m->workLocation?->name ?? '',
                $local($m->start_at, $m->timezone), $local($m->end_at, $m->timezone), $m->timezone ?: $tz,
                $m->start_at && $m->end_at ? (int) $m->start_at->diffInMinutes($m->end_at) : 0, $this->label('shift', $m->status),
            ],
            'leaves' => [
                ...$person($m->employee), $m->leaveType?->name ?? '', $m->date_from?->toDateString(), $m->date_to?->toDateString(),
                $m->start_time ?? '', $m->end_time ?? '', self::hundredths($m->days_count_hundredths), self::hundredths($m->hours_count_hundredths),
                $this->label('leave', $m->status), $m->reviewed_at ? $local($m->reviewed_at) : '',
            ],
        };
    }

    /** status / yes-no codes in the interface language ; an unknown code is kept as is (never invented) */
    private function label(string $group, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $key = "workforceExport.values.$group.$code";

        return __($key) === $key ? $code : __($key);
    }

    public static function hhmm(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    /** 250 → « 2,5 » (French decimal comma : the file is read in a spreadsheet) */
    public static function hundredths(?int $value): string
    {
        return $value === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.'));
    }
}
