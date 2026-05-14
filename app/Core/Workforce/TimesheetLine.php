<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TimesheetLine extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_timesheet_lines';

    // --- Anomaly type constants ---

    const ANOMALY_MISSING_CLOCK_OUT = 'missing_clock_out';
    const ANOMALY_MISSING_TIME_ENTRY = 'missing_time_entry';
    const ANOMALY_OVERTIME_DAILY = 'overtime_daily';
    const ANOMALY_OVERTIME_WEEKLY = 'overtime_weekly';
    const ANOMALY_INSUFFICIENT_BREAK = 'insufficient_break';
    const ANOMALY_UNPLANNED_WORK = 'unplanned_work';
    const ANOMALY_LEAVE_CONFLICT = 'leave_conflict';
    const ANOMALY_LATE_ARRIVAL = 'late_arrival';
    const ANOMALY_EARLY_DEPARTURE = 'early_departure';

    // --- Anomaly severity ---

    const SEVERITY_ERROR = 'error';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_INFO = 'info';

    const ANOMALY_SEVERITIES = [
        self::ANOMALY_MISSING_CLOCK_OUT => self::SEVERITY_WARNING,
        self::ANOMALY_MISSING_TIME_ENTRY => self::SEVERITY_INFO,
        self::ANOMALY_OVERTIME_DAILY => self::SEVERITY_WARNING,
        self::ANOMALY_OVERTIME_WEEKLY => self::SEVERITY_WARNING,
        self::ANOMALY_INSUFFICIENT_BREAK => self::SEVERITY_WARNING,
        self::ANOMALY_UNPLANNED_WORK => self::SEVERITY_INFO,
        self::ANOMALY_LEAVE_CONFLICT => self::SEVERITY_ERROR,
        self::ANOMALY_LATE_ARRIVAL => self::SEVERITY_INFO,
        self::ANOMALY_EARLY_DEPARTURE => self::SEVERITY_INFO,
    ];

    protected $fillable = [
        'company_id',
        'timesheet_period_id',
        'employee_id',
        'date',
        'worked_minutes',
        'break_minutes',
        'daily_overtime_minutes',
        'planned_minutes',
        'leave_minutes',
        'leave_type_code',
        'is_leave_day',
        'is_rest_day',
        'anomalies',
        'source_snapshot',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'worked_minutes' => 'integer',
        'break_minutes' => 'integer',
        'daily_overtime_minutes' => 'integer',
        'planned_minutes' => 'integer',
        'leave_minutes' => 'integer',
        'is_leave_day' => 'boolean',
        'is_rest_day' => 'boolean',
        'anomalies' => 'array',
        'source_snapshot' => 'array',
        'metadata' => 'array',
    ];

    // --- Boot: enforce I13 (lines immutable unless parent is draft) ---

    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $line) {
            $period = $line->timesheetPeriod;
            if ($period && $period->status !== TimesheetPeriod::STATUS_DRAFT) {
                throw new \RuntimeException(
                    "Cannot update timesheet line: parent period is in status '{$period->status}', expected 'draft'."
                );
            }
        });

        static::deleting(function (self $line) {
            $period = $line->timesheetPeriod;
            if ($period && $period->status !== TimesheetPeriod::STATUS_DRAFT) {
                throw new \RuntimeException(
                    "Cannot delete timesheet line: parent period is in status '{$period->status}', expected 'draft'."
                );
            }
        });
    }

    // --- Relationships ---

    public function timesheetPeriod()
    {
        return $this->belongsTo(TimesheetPeriod::class, 'timesheet_period_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // --- Helpers ---

    public function hasAnomalies(): bool
    {
        return ! empty($this->anomalies);
    }

    public function hasErrorAnomalies(): bool
    {
        return collect($this->anomalies ?? [])
            ->contains(fn ($a) => ($a['severity'] ?? null) === self::SEVERITY_ERROR);
    }

    public function anomalyCount(): int
    {
        return count($this->anomalies ?? []);
    }
}
