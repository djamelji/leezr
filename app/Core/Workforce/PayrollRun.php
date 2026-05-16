<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PayrollRun represents a payroll computation batch for a specific period.
 *
 * Named "Run" (not "Period") to distinguish from TimesheetPeriod —
 * a Run is a computation event, not a time range.
 *
 * gross_basis_cents = payroll preparation estimate, not official gross salary.
 * Official gross/net will come from PayrollCalculationResult in Phase 3.
 */
class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_payroll_runs';

    const STATUS_DRAFT = 'draft';
    const STATUS_COMPUTED = 'computed';
    const STATUS_VALIDATED = 'validated';
    const STATUS_EXPORTED = 'exported';

    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_COMPUTED],
        self::STATUS_COMPUTED => [self::STATUS_VALIDATED, self::STATUS_DRAFT],
        self::STATUS_VALIDATED => [self::STATUS_EXPORTED],
        self::STATUS_EXPORTED => [],
    ];

    const PAY_FREQUENCY_SCOPES = ['monthly', 'biweekly', 'weekly', 'mixed'];

    const ANOMALY_MISSING_TIMESHEET = 'missing_timesheet';
    const ANOMALY_MISSING_COMPENSATION = 'missing_compensation';
    const ANOMALY_TIMESHEET_HAS_ERRORS = 'timesheet_has_errors';
    const ANOMALY_ZERO_WORKED = 'zero_worked_minutes';
    const ANOMALY_COMPENSATION_GAP = 'compensation_gap';
    const ANOMALY_ZERO_BASE_SALARY = 'zero_base_salary';
    const ANOMALY_INVALID_WEEKLY_HOURS = 'invalid_weekly_hours';

    const ANOMALY_SEVERITIES = [
        self::ANOMALY_MISSING_TIMESHEET => 'error',
        self::ANOMALY_MISSING_COMPENSATION => 'error',
        self::ANOMALY_TIMESHEET_HAS_ERRORS => 'warning',
        self::ANOMALY_ZERO_WORKED => 'warning',
        self::ANOMALY_COMPENSATION_GAP => 'warning',
        self::ANOMALY_ZERO_BASE_SALARY => 'error',
        self::ANOMALY_INVALID_WEEKLY_HOURS => 'error',
    ];

    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'pay_frequency_scope',
        'status',
        'currency',
        'total_gross_cents',
        'total_worked_minutes',
        'total_overtime_minutes',
        'total_leave_days_hundredths',
        'employee_count',
        'anomalies',
        'anomaly_count',
        'computed_by',
        'computed_at',
        'validated_by',
        'validated_at',
        'validation_note',
        'exported_by',
        'exported_at',
        'export_format',
        'export_path',
        'exported_snapshot',
        'policy_snapshot',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_gross_cents' => 'integer',
        'total_worked_minutes' => 'integer',
        'total_overtime_minutes' => 'integer',
        'total_leave_days_hundredths' => 'integer',
        'employee_count' => 'integer',
        'anomalies' => 'array',
        'anomaly_count' => 'integer',
        'computed_at' => 'datetime',
        'validated_at' => 'datetime',
        'exported_at' => 'datetime',
        'exported_snapshot' => 'array',
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
            throw new \DomainException("Cannot transition PayrollRun from {$this->status} to {$newStatus}");
        }
        $this->status = $newStatus;
    }

    // --- Scopes ---

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeComputed($query)
    {
        return $query->where('status', self::STATUS_COMPUTED);
    }

    public function scopeValidated($query)
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    public function scopeExported($query)
    {
        return $query->where('status', self::STATUS_EXPORTED);
    }

    public function scopeForPeriod($query, $periodStart, $periodEnd)
    {
        return $query->where('period_start', $periodStart)->where('period_end', $periodEnd);
    }

    // --- Relationships ---

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'payroll_run_id');
    }

    public function computedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'computed_by');
    }

    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'validated_by');
    }

    public function exportedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'exported_by');
    }

    // --- Helpers ---

    public function hasErrorAnomalies(): bool
    {
        $anomalies = $this->anomalies ?? [];

        foreach ($anomalies as $anomaly) {
            $severity = self::ANOMALY_SEVERITIES[$anomaly['type'] ?? ''] ?? null;
            if ($severity === 'error') {
                return true;
            }
        }

        return false;
    }

    public function recomputeTotals(): void
    {
        $lines = $this->lines()->get();

        $this->total_gross_cents = $lines->sum('gross_basis_cents');
        $this->total_worked_minutes = $lines->sum('worked_minutes');
        $this->total_overtime_minutes = $lines->sum('total_overtime_minutes');
        $this->total_leave_days_hundredths = $lines->sum('leave_days_hundredths');
        $this->employee_count = $lines->count();
    }

    public function buildIdempotencyKey(): string
    {
        return "payroll:{$this->company_id}:{$this->period_start->format('Y-m-d')}:{$this->period_end->format('Y-m-d')}";
    }
}
