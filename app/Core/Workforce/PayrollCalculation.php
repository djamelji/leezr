<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PayrollCalculation represents the Brut->Net calculation result for a PayrollLine.
 *
 * Stores contribution lines, tax breakdown, benefits, deductions, and the final
 * net payable amount. Immutable once validated.
 */
class PayrollCalculation extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_payroll_calculations';

    const STATUS_CALCULATED = 'calculated';
    const STATUS_VALIDATED = 'validated';

    protected $fillable = [
        'company_id',
        'payroll_line_id',
        'rule_version',
        'plafond_ss_monthly_cents',
        'gross_total_cents',
        'contributions_employee_cents',
        'contributions_employer_cents',
        'total_cost_employer_cents',
        'taxable_income_cents',
        'tax_cents',
        'net_before_tax_cents',
        'net_payable_cents',
        'benefits_cents',
        'deductions_cents',
        'contribution_lines',
        'tax_breakdown',
        'benefit_lines',
        'deduction_lines',
        'net_social_cents',
        'relief_lines',
        'blocking_anomalies',
        'calculation_snapshot',
        'status',
        'calculated_at',
        'calculated_by',
    ];

    protected $casts = [
        'plafond_ss_monthly_cents' => 'integer',
        'gross_total_cents' => 'integer',
        'contributions_employee_cents' => 'integer',
        'contributions_employer_cents' => 'integer',
        'total_cost_employer_cents' => 'integer',
        'taxable_income_cents' => 'integer',
        'tax_cents' => 'integer',
        'net_before_tax_cents' => 'integer',
        'net_payable_cents' => 'integer',
        'benefits_cents' => 'integer',
        'deductions_cents' => 'integer',
        'contribution_lines' => 'array',
        'tax_breakdown' => 'array',
        'benefit_lines' => 'array',
        'deduction_lines' => 'array',
        'net_social_cents' => 'integer',
        'relief_lines' => 'array',
        'blocking_anomalies' => 'array',
        'calculation_snapshot' => 'array',
        'status' => 'string',
        'calculated_at' => 'datetime',
    ];

    // --- Boot: enforce immutability when status is validated ---

    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $calculation) {
            if ($calculation->getOriginal('status') === self::STATUS_VALIDATED) {
                throw new \RuntimeException(
                    'Cannot update PayrollCalculation: record is validated and immutable.'
                );
            }
        });
    }

    // --- Relationships ---

    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /**
     * Get the employee through the payroll line.
     */
    public function getEmployeeAttribute(): ?Employee
    {
        return $this->payrollLine?->employee;
    }

    public function ytdSnapshot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PayrollYtdSnapshot::class, 'payroll_calculation_id');
    }

    // --- Helpers ---

    public function hasBlockingAnomalies(): bool
    {
        return ! empty($this->blocking_anomalies);
    }

    // --- Scopes ---

    public function scopeCalculated($query)
    {
        return $query->where('status', self::STATUS_CALCULATED);
    }

    public function scopeValidated($query)
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }
}
