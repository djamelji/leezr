<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollYtdSnapshot;
use Illuminate\Support\Facades\DB;

class YtdCalculator
{
    const YTD_VERSION = 'ytd-snapshot-v1';

    /**
     * Compute YTD snapshot data for an employee at month M of a fiscal year.
     * Pure computation — returns array, ZERO DB writes.
     *
     * Aggregates PayrollCalculations for this employee in the fiscal year,
     * from month 1 to $periodMonth inclusive.
     *
     * @param  bool  $includeCalculated  Include 'calculated' status (not just 'validated')
     * @return array YTD snapshot data (ready for PayrollYtdSnapshot::create)
     */
    public static function compute(
        int $companyId,
        int $employeeId,
        int $fiscalYear,
        int $periodMonth,
        bool $includeCalculated = false,
    ): array {
        $calculations = self::fetchCalculations($companyId, $employeeId, $fiscalYear, $periodMonth, $includeCalculated);

        return self::aggregate($calculations, $companyId, $employeeId, $fiscalYear, $periodMonth);
    }

    /**
     * Get prior months' cumulative data (months 1..M-1).
     * Returns null if no prior data exists.
     *
     * @return array|null Prior cumuls as array, or null if month 1 or no prior data
     */
    public static function getPriorCumuls(
        int $companyId,
        int $employeeId,
        int $fiscalYear,
        int $currentMonth,
        bool $includeCalculated = false,
    ): ?array {
        if ($currentMonth <= 1) {
            return null;
        }

        $calculations = self::fetchCalculations($companyId, $employeeId, $fiscalYear, $currentMonth - 1, $includeCalculated);

        if ($calculations->isEmpty()) {
            return null;
        }

        return self::aggregate($calculations, $companyId, $employeeId, $fiscalYear, $currentMonth - 1);
    }

    /**
     * Recalculate all YTD snapshots for an employee across a full fiscal year.
     * Returns array of snapshot data for each month that has validated calculations.
     * Pure — ZERO DB writes.
     *
     * @return array<int, array> Keyed by period_month => snapshot data
     */
    public static function recalculateYear(
        int $companyId,
        int $employeeId,
        int $fiscalYear,
        bool $includeCalculated = false,
    ): array {
        $snapshots = [];

        for ($month = 1; $month <= 12; $month++) {
            $calculations = self::fetchCalculations($companyId, $employeeId, $fiscalYear, $month, $includeCalculated);

            // Only produce snapshot if this month has a calculation
            $hasCurrentMonth = $calculations->contains(function ($calc) use ($month) {
                return self::extractMonth($calc) === $month;
            });

            if ($hasCurrentMonth) {
                $snapshots[$month] = self::aggregate($calculations, $companyId, $employeeId, $fiscalYear, $month);
            }
        }

        return $snapshots;
    }

    // --- Private helpers ---

    /**
     * Fetch PayrollCalculations for employee in fiscal year, up to month M.
     * By default only 'validated'. With $includeCalculated, also includes 'calculated'.
     */
    private static function fetchCalculations(
        int $companyId,
        int $employeeId,
        int $fiscalYear,
        int $upToMonth,
        bool $includeCalculated = false,
    ) {
        $yearStart = "{$fiscalYear}-01-01";
        // Use < first-day-of-next-month instead of <= last-day-of-month
        // to avoid SQLite string comparison issue with datetime suffixes
        if ($upToMonth >= 12) {
            $nextMonthStart = ($fiscalYear + 1) . '-01-01';
        } else {
            $nextMonthStart = sprintf('%d-%02d-01', $fiscalYear, $upToMonth + 1);
        }

        $statuses = [PayrollCalculation::STATUS_VALIDATED];
        if ($includeCalculated) {
            $statuses[] = PayrollCalculation::STATUS_CALCULATED;
        }

        $ids = DB::table('workforce_payroll_calculations')
            ->join('workforce_payroll_lines', 'workforce_payroll_lines.id', '=', 'workforce_payroll_calculations.payroll_line_id')
            ->join('workforce_payroll_runs', 'workforce_payroll_runs.id', '=', 'workforce_payroll_lines.payroll_run_id')
            ->where('workforce_payroll_calculations.company_id', $companyId)
            ->whereIn('workforce_payroll_calculations.status', $statuses)
            ->where('workforce_payroll_lines.employee_id', $employeeId)
            ->where('workforce_payroll_runs.period_start', '>=', $yearStart)
            ->where('workforce_payroll_runs.period_end', '<', $nextMonthStart)
            ->pluck('workforce_payroll_calculations.id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return PayrollCalculation::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->with(['payrollLine' => fn ($q) => $q->withoutGlobalScopes()->with(['payrollRun' => fn ($q2) => $q2->withoutGlobalScopes()])])
            ->get();
    }

    /**
     * Aggregate a collection of PayrollCalculations into YTD snapshot data.
     */
    private static function aggregate(
        $calculations,
        int $companyId,
        int $employeeId,
        int $fiscalYear,
        int $periodMonth,
    ): array {
        $ytdGrossTotal = 0;
        $ytdGrossBasis = 0;
        $ytdContribEmployee = 0;
        $ytdContribEmployer = 0;
        $ytdTotalCostEmployer = 0;
        $ytdPlafondSS = 0;
        $ytdTaxableIncome = 0;
        $ytdTax = 0;
        $ytdNetBeforeTax = 0;
        $ytdNetPayable = 0;
        $ytdBenefits = 0;
        $ytdDeductions = 0;
        $monthsIncluded = [];
        $ruleVersionsUsed = [];
        $contributionLinesCumul = [];
        $latestCalcRuleVersion = null;
        $latestResolverVersion = null;
        $latestCalculationId = null;

        foreach ($calculations as $calc) {
            $month = self::extractMonth($calc);
            $monthsIncluded[] = $month;

            $ytdGrossTotal += $calc->gross_total_cents;
            $ytdGrossBasis += $calc->payrollLine->gross_basis_cents;
            $ytdContribEmployee += $calc->contributions_employee_cents;
            $ytdContribEmployer += $calc->contributions_employer_cents;
            $ytdTotalCostEmployer += $calc->total_cost_employer_cents;
            $ytdPlafondSS += $calc->plafond_ss_monthly_cents;
            $ytdTaxableIncome += $calc->taxable_income_cents;
            $ytdTax += $calc->tax_cents;
            $ytdNetBeforeTax += $calc->net_before_tax_cents;
            $ytdNetPayable += $calc->net_payable_cents;
            $ytdBenefits += $calc->benefits_cents;
            $ytdDeductions += $calc->deductions_cents;

            // Track rule versions per month
            $ruleVersionsUsed[$month] = $calc->rule_version;

            // Accumulate contribution lines by code
            $lines = $calc->contribution_lines['lines'] ?? [];
            foreach ($lines as $line) {
                $code = $line['code'] ?? 'unknown';
                if (! isset($contributionLinesCumul[$code])) {
                    $contributionLinesCumul[$code] = [
                        'code' => $code,
                        'label' => $line['label'] ?? $code,
                        'category' => $line['category'] ?? 'other',
                        'bulletin_group' => $line['bulletin_group'] ?? null,
                        'ytd_employee_cents' => 0,
                        'ytd_employer_cents' => 0,
                    ];
                }
                $contributionLinesCumul[$code]['ytd_employee_cents'] += $line['employee_cents'] ?? 0;
                $contributionLinesCumul[$code]['ytd_employer_cents'] += $line['employer_cents'] ?? 0;
            }

            // Track latest for versioning
            if ($month === $periodMonth || $month > ($latestCalculationId ? self::extractMonth($calculations->firstWhere('id', $latestCalculationId)) : 0)) {
                $latestCalcRuleVersion = $calc->rule_version;
                $latestResolverVersion = $calc->calculation_snapshot['resolver_version'] ?? null;
                $latestCalculationId = $calc->id;
            }
        }

        sort($monthsIncluded);
        ksort($ruleVersionsUsed);

        // Plafonnement cumulé
        $ytdBasePlafonnee = min($ytdGrossTotal, $ytdPlafondSS);
        $ytdBaseTranche2 = max(0, min($ytdGrossTotal, $ytdPlafondSS * 8) - $ytdPlafondSS);

        return [
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'payroll_calculation_id' => $latestCalculationId,
            'ytd_gross_total_cents' => $ytdGrossTotal,
            'ytd_gross_basis_cents' => $ytdGrossBasis,
            'ytd_contributions_employee_cents' => $ytdContribEmployee,
            'ytd_contributions_employer_cents' => $ytdContribEmployer,
            'ytd_total_cost_employer_cents' => $ytdTotalCostEmployer,
            'ytd_plafond_ss_cents' => $ytdPlafondSS,
            'ytd_base_plafonnee_cents' => $ytdBasePlafonnee,
            'ytd_base_tranche2_cents' => $ytdBaseTranche2,
            'ytd_taxable_income_cents' => $ytdTaxableIncome,
            'ytd_tax_cents' => $ytdTax,
            'ytd_net_before_tax_cents' => $ytdNetBeforeTax,
            'ytd_net_payable_cents' => $ytdNetPayable,
            'ytd_benefits_cents' => $ytdBenefits,
            'ytd_deductions_cents' => $ytdDeductions,
            'ytd_contribution_lines' => array_values($contributionLinesCumul),
            'snapshot_version' => self::YTD_VERSION,
            'calc_rule_version' => $latestCalcRuleVersion ?? PayrollYtdSnapshot::SNAPSHOT_VERSION,
            'resolver_version' => $latestResolverVersion,
            'months_included' => $monthsIncluded,
            'rule_versions_used' => $ruleVersionsUsed,
        ];
    }

    /**
     * Extract month from a PayrollCalculation via its PayrollRun.period_start.
     */
    private static function extractMonth(PayrollCalculation $calc): int
    {
        return (int) date('n', strtotime($calc->payrollLine->payrollRun->period_start));
    }
}
