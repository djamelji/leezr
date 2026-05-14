<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use App\Core\Workforce\PayrollCalculation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PayrollLine represents one employee's payroll data for a PayrollRun.
 *
 * gross_basis_cents = payroll preparation estimate, not official gross salary.
 * Official gross/net will come from PayrollCalculationResult in Phase 3.
 *
 * Immutable when parent PayrollRun is not in draft status.
 */
class PayrollLine extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_payroll_lines';

    const FORMULA_VERSION = 'payroll-prep-v1';

    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'employee_id',
        'timesheet_period_id',
        'worked_minutes',
        'break_minutes',
        'daily_overtime_minutes',
        'weekly_overtime_minutes',
        'total_overtime_minutes',
        'planned_minutes',
        'leave_days_hundredths',
        'paid_leave_days_hundredths',
        'unpaid_leave_days_hundredths',
        'leave_minutes',
        'base_salary_cents',
        'overtime_rate_bps',
        'gross_basis_cents',
        'gross_breakdown',
        'compensation_snapshot',
        'timesheet_snapshot',
        'export_payload',
        'anomalies',
        'metadata',
    ];

    protected $casts = [
        'worked_minutes' => 'integer',
        'break_minutes' => 'integer',
        'daily_overtime_minutes' => 'integer',
        'weekly_overtime_minutes' => 'integer',
        'total_overtime_minutes' => 'integer',
        'planned_minutes' => 'integer',
        'leave_days_hundredths' => 'integer',
        'paid_leave_days_hundredths' => 'integer',
        'unpaid_leave_days_hundredths' => 'integer',
        'leave_minutes' => 'integer',
        'base_salary_cents' => 'integer',
        'overtime_rate_bps' => 'integer',
        'gross_basis_cents' => 'integer',
        'gross_breakdown' => 'array',
        'compensation_snapshot' => 'array',
        'timesheet_snapshot' => 'array',
        'export_payload' => 'array',
        'anomalies' => 'array',
        'metadata' => 'array',
    ];

    // --- Boot: enforce immutability when parent PayrollRun is not draft ---

    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $line) {
            $run = $line->payrollRun;
            if ($run && $run->status !== PayrollRun::STATUS_DRAFT) {
                throw new \RuntimeException(
                    "Cannot update PayrollLine: parent PayrollRun is in status '{$run->status}', not draft."
                );
            }
        });

        static::deleting(function (self $line) {
            $run = $line->payrollRun;
            if ($run && $run->status !== PayrollRun::STATUS_DRAFT) {
                throw new \RuntimeException(
                    "Cannot delete PayrollLine: parent PayrollRun is in status '{$run->status}', not draft."
                );
            }
        });
    }

    // --- Relationships ---

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function calculation(): HasOne
    {
        return $this->hasOne(PayrollCalculation::class, 'payroll_line_id');
    }

    /**
     * The generated payslip draft document for this line.
     */
    public function payslipDraft(): HasOne
    {
        return $this->hasOne(\App\Core\Documents\GeneratedDocument::class, 'subject_id')
            ->where('subject_type', 'payroll_line');
    }

    public function timesheetPeriod(): BelongsTo
    {
        return $this->belongsTo(TimesheetPeriod::class, 'timesheet_period_id');
    }

    // --- Helpers ---

    public function hasAnomalies(): bool
    {
        return ! empty($this->anomalies);
    }

    public function hasErrorAnomalies(): bool
    {
        foreach ($this->anomalies ?? [] as $anomaly) {
            $severity = PayrollRun::ANOMALY_SEVERITIES[$anomaly['type'] ?? ''] ?? null;
            if ($severity === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the export_payload for this line.
     */
    public function buildExportPayload(): array
    {
        $compensation = $this->compensation_snapshot ?? [];

        return [
            'employee_id' => $this->employee_id,
            'employee_number' => $this->employee?->employee_number,
            'period_start' => $this->payrollRun?->period_start?->format('Y-m-d'),
            'period_end' => $this->payrollRun?->period_end?->format('Y-m-d'),
            'worked_minutes' => $this->worked_minutes,
            'break_minutes' => $this->break_minutes,
            'total_overtime_minutes' => $this->total_overtime_minutes,
            'leave_days_hundredths' => $this->leave_days_hundredths,
            'paid_leave_days_hundredths' => $this->paid_leave_days_hundredths,
            'unpaid_leave_days_hundredths' => $this->unpaid_leave_days_hundredths,
            'base_salary_cents' => $this->base_salary_cents,
            'gross_basis_cents' => $this->gross_basis_cents,
            'currency' => $compensation['currency'] ?? null,
            'formula_version' => self::FORMULA_VERSION,
        ];
    }
}
