<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollYtdSnapshot extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_payroll_ytd_snapshots';

    const SNAPSHOT_VERSION = 'ytd-snapshot-v1';

    protected $fillable = [
        'company_id',
        'employee_id',
        'fiscal_year',
        'period_month',
        'payroll_calculation_id',
        'ytd_gross_total_cents',
        'ytd_gross_basis_cents',
        'ytd_contributions_employee_cents',
        'ytd_contributions_employer_cents',
        'ytd_total_cost_employer_cents',
        'ytd_plafond_ss_cents',
        'ytd_base_plafonnee_cents',
        'ytd_base_tranche2_cents',
        'ytd_taxable_income_cents',
        'ytd_tax_cents',
        'ytd_net_before_tax_cents',
        'ytd_net_payable_cents',
        'ytd_benefits_cents',
        'ytd_deductions_cents',
        'ytd_contribution_lines',
        'snapshot_version',
        'calc_rule_version',
        'resolver_version',
        'months_included',
        'rule_versions_used',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'period_month' => 'integer',
        'ytd_gross_total_cents' => 'integer',
        'ytd_gross_basis_cents' => 'integer',
        'ytd_contributions_employee_cents' => 'integer',
        'ytd_contributions_employer_cents' => 'integer',
        'ytd_total_cost_employer_cents' => 'integer',
        'ytd_plafond_ss_cents' => 'integer',
        'ytd_base_plafonnee_cents' => 'integer',
        'ytd_base_tranche2_cents' => 'integer',
        'ytd_taxable_income_cents' => 'integer',
        'ytd_tax_cents' => 'integer',
        'ytd_net_before_tax_cents' => 'integer',
        'ytd_net_payable_cents' => 'integer',
        'ytd_benefits_cents' => 'integer',
        'ytd_deductions_cents' => 'integer',
        'ytd_contribution_lines' => 'array',
        'months_included' => 'array',
        'rule_versions_used' => 'array',
    ];

    // --- Relationships ---

    public function payrollCalculation(): BelongsTo
    {
        return $this->belongsTo(PayrollCalculation::class, 'payroll_calculation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // --- Scopes ---

    public function scopeForYear($query, int $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    public function scopeForMonth($query, int $periodMonth)
    {
        return $query->where('period_month', $periodMonth);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeUpToMonth($query, int $periodMonth)
    {
        return $query->where('period_month', '<=', $periodMonth);
    }
}
