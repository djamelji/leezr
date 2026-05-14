<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TimesheetPeriod extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_timesheet_periods';

    // --- Status constants ---

    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_LOCKED = 'locked';

    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_LOCKED],
        self::STATUS_LOCKED => [],
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'period_start',
        'period_end',
        'status',
        'total_worked_minutes',
        'total_break_minutes',
        'total_overtime_minutes',
        'total_planned_minutes',
        'total_leave_days_hundredths',
        'anomaly_count',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_note',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'locked_at',
        'locked_by',
        'policy_snapshot',
        'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_worked_minutes' => 'integer',
        'total_break_minutes' => 'integer',
        'total_overtime_minutes' => 'integer',
        'total_planned_minutes' => 'integer',
        'total_leave_days_hundredths' => 'integer',
        'anomaly_count' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'locked_at' => 'datetime',
        'policy_snapshot' => 'array',
        'metadata' => 'array',
    ];

    // --- State Machine ---

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition timesheet from '{$this->status}' to '{$newStatus}'."
            );
        }

        $this->status = $newStatus;
    }

    // --- Scopes ---

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeLocked($query)
    {
        return $query->where('status', self::STATUS_LOCKED);
    }

    public function scopeForPeriod($query, $periodStart, $periodEnd)
    {
        return $query->where('period_start', $periodStart)->where('period_end', $periodEnd);
    }

    public function scopeOverlapping($query, $periodStart, $periodEnd)
    {
        return $query->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart);
    }

    // --- Relationships ---

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function lines()
    {
        return $this->hasMany(TimesheetLine::class, 'timesheet_period_id')->orderBy('date');
    }

    public function submitter()
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'rejected_by');
    }

    public function locker()
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'locked_by');
    }

    // --- Helpers ---

    public function hasErrorAnomalies(): bool
    {
        return $this->lines()
            ->whereJsonContains('anomalies', [['severity' => 'error']])
            ->exists();
    }

    public function recomputeTotals(): void
    {
        $lines = $this->lines()->get();

        $this->total_worked_minutes = $lines->sum('worked_minutes');
        $this->total_break_minutes = $lines->sum('break_minutes');
        $this->total_overtime_minutes = $lines->sum('daily_overtime_minutes');
        $this->total_planned_minutes = $lines->sum('planned_minutes');
        $this->total_leave_days_hundredths = $lines->where('is_leave_day', true)->sum(function ($line) {
            $snapshot = $line->source_snapshot ?? [];
            $leaves = $snapshot['leave_requests'] ?? [];

            return collect($leaves)->sum('days_hundredths');
        });
        $this->anomaly_count = $lines->sum(function ($line) {
            return count($line->anomalies ?? []);
        });
    }
}
