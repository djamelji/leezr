<?php

namespace App\Core\Documents\Services;

use App\Core\Fields\FieldValue;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\BulletinGroup;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\PayrollYtdSnapshot;
use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves template variables from structured models + dynamic FieldValues.
 *
 * Returns a flat associative array with namespaced keys:
 *   employee.full_name, contract.contract_type, company.name, etc.
 *
 * Every resolver includes: company.*, today, today_formatted, field.* (FieldValues)
 */
class DocumentVariableResolver
{
    const RESOLVER_VERSION = 'v2';

    public static function resolve(string $subjectType, Model $subject, Company $company): array
    {
        $base = self::resolveCompany($company);
        $base['today'] = now()->format('Y-m-d');
        $base['today_formatted'] = now()->translatedFormat('j F Y');
        $base['resolver_version'] = self::RESOLVER_VERSION;

        $specific = match ($subjectType) {
            'employee' => self::resolveEmployee($subject, $company),
            'contract' => self::resolveContract($subject, $company),
            'leave_request' => self::resolveLeaveRequest($subject, $company),
            'payroll_run' => self::resolvePayrollRun($subject, $company),
            'payroll_line' => self::resolvePayrollLine($subject, $company),
            default => throw new \DomainException("Unknown subject type: {$subjectType}"),
        };

        // Dynamic FieldValues for the subject
        $fields = self::resolveFieldValues($subject);

        return array_merge($base, $specific, $fields);
    }

    /**
     * Return the schema of all available variables for a subject type.
     */
    public static function availableVariables(string $subjectType): array
    {
        $common = [
            ['key' => 'company.name', 'type' => 'string', 'label' => 'Company name'],
            ['key' => 'company.legal_status', 'type' => 'string', 'label' => 'Legal status'],
            ['key' => 'company.market', 'type' => 'string', 'label' => 'Market'],
            ['key' => 'company.siret', 'type' => 'string', 'label' => 'SIRET'],
            ['key' => 'company.naf_code', 'type' => 'string', 'label' => 'Code NAF/APE'],
            ['key' => 'today', 'type' => 'date', 'label' => 'Today (Y-m-d)'],
            ['key' => 'today_formatted', 'type' => 'string', 'label' => 'Today (formatted)'],
            ['key' => 'resolver_version', 'type' => 'string', 'label' => 'Resolver version'],
        ];

        $specific = match ($subjectType) {
            'employee' => self::employeeSchema(),
            'contract' => self::contractSchema(),
            'leave_request' => self::leaveRequestSchema(),
            'payroll_run' => self::payrollRunSchema(),
            'payroll_line' => self::payrollLineSchema(),
            default => [],
        };

        return array_merge($common, $specific);
    }

    // --- Company ---

    private static function resolveCompany(Company $company): array
    {
        return [
            'company.name' => $company->name ?? '',
            'company.legal_status' => $company->legalStatus?->name ?? '',
            'company.market' => $company->market?->name ?? '',
            'company.slug' => $company->slug ?? '',
            'company.siret' => $company->siret ?? '',
            'company.naf_code' => $company->naf_code ?? '',
        ];
    }

    // --- Employee ---

    private static function resolveEmployee(Employee $employee, Company $company): array
    {
        $contract = $employee->currentContract;
        $compensation = $contract?->currentCompensation;

        return array_merge(
            self::employeeFields($employee),
            $contract ? self::contractFields($contract) : [],
            $compensation ? self::compensationFields($compensation) : [],
        );
    }

    private static function employeeFields(Employee $employee): array
    {
        return [
            'employee.full_name' => $employee->full_name ?? '',
            'employee.first_name' => $employee->first_name ?? '',
            'employee.last_name' => $employee->last_name ?? '',
            'employee.employee_number' => $employee->employee_number ?? '',
            'employee.email' => $employee->email ?? '',
            'employee.phone' => $employee->phone ?? '',
            'employee.hire_date' => $employee->hire_date?->format('Y-m-d') ?? '',
            'employee.hire_date_formatted' => $employee->hire_date?->translatedFormat('j F Y') ?? '',
            'employee.status' => $employee->status ?? '',
        ];
    }

    private static function employeeSchema(): array
    {
        return [
            ['key' => 'employee.full_name', 'type' => 'string', 'label' => 'Full name'],
            ['key' => 'employee.first_name', 'type' => 'string', 'label' => 'First name'],
            ['key' => 'employee.last_name', 'type' => 'string', 'label' => 'Last name'],
            ['key' => 'employee.employee_number', 'type' => 'string', 'label' => 'Employee number'],
            ['key' => 'employee.email', 'type' => 'string', 'label' => 'Email'],
            ['key' => 'employee.phone', 'type' => 'string', 'label' => 'Phone'],
            ['key' => 'employee.hire_date', 'type' => 'date', 'label' => 'Hire date'],
            ['key' => 'employee.hire_date_formatted', 'type' => 'string', 'label' => 'Hire date (formatted)'],
            ['key' => 'employee.status', 'type' => 'string', 'label' => 'Status'],
            ['key' => 'contract.contract_type', 'type' => 'string', 'label' => 'Contract type'],
            ['key' => 'contract.contract_type_label', 'type' => 'string', 'label' => 'Contract type label'],
            ['key' => 'contract.work_model', 'type' => 'string', 'label' => 'Work model'],
            ['key' => 'contract.weekly_hours', 'type' => 'number', 'label' => 'Weekly hours'],
            ['key' => 'contract.start_date', 'type' => 'date', 'label' => 'Contract start'],
            ['key' => 'contract.start_date_formatted', 'type' => 'string', 'label' => 'Contract start (formatted)'],
            ['key' => 'contract.end_date', 'type' => 'date', 'label' => 'Contract end'],
            ['key' => 'contract.probation_end_date', 'type' => 'date', 'label' => 'Probation end'],
            ['key' => 'compensation.base_salary_formatted', 'type' => 'string', 'label' => 'Base salary'],
            ['key' => 'compensation.currency', 'type' => 'string', 'label' => 'Currency'],
            ['key' => 'compensation.pay_frequency', 'type' => 'string', 'label' => 'Pay frequency'],
        ];
    }

    // --- Contract ---

    private static function resolveContract(EmploymentContract $contract, Company $company): array
    {
        $employee = $contract->employee;
        $compensation = $contract->currentCompensation;

        return array_merge(
            $employee ? self::employeeFields($employee) : [],
            self::contractFields($contract),
            $compensation ? self::compensationFields($compensation) : [],
        );
    }

    private static function contractFields(EmploymentContract $contract): array
    {
        $typeLabels = [
            'cdi' => 'CDI', 'cdd' => 'CDD', 'stage' => 'Stage',
            'alternance' => 'Alternance', 'freelance' => 'Freelance',
        ];

        return [
            'contract.contract_type' => $contract->contract_type ?? '',
            'contract.contract_type_label' => $typeLabels[$contract->contract_type ?? ''] ?? $contract->contract_type ?? '',
            'contract.work_model' => $contract->work_model_key ?? '',
            'contract.weekly_hours' => $contract->weekly_hours ?? '',
            'contract.start_date' => $contract->start_date?->format('Y-m-d') ?? '',
            'contract.start_date_formatted' => $contract->start_date?->translatedFormat('j F Y') ?? '',
            'contract.end_date' => $contract->end_date?->format('Y-m-d') ?? '',
            'contract.end_date_formatted' => $contract->end_date?->translatedFormat('j F Y') ?? '',
            'contract.probation_end_date' => $contract->probation_end_date?->format('Y-m-d') ?? '',
            'contract.probation_end_date_formatted' => $contract->probation_end_date?->translatedFormat('j F Y') ?? '',
            'contract.status' => $contract->status ?? '',
        ];
    }

    private static function contractSchema(): array
    {
        return array_merge(
            self::employeeSchema(),
            [
                ['key' => 'contract.status', 'type' => 'string', 'label' => 'Contract status'],
                ['key' => 'contract.end_date_formatted', 'type' => 'string', 'label' => 'Contract end (formatted)'],
                ['key' => 'contract.probation_end_date_formatted', 'type' => 'string', 'label' => 'Probation end (formatted)'],
            ]
        );
    }

    // --- Compensation ---

    private static function compensationFields(CompensationPlan $comp): array
    {
        $formatted = number_format($comp->base_salary_cents / 100, 2, ',', ' ');
        $currency = $comp->currency ?? 'EUR';

        return [
            'compensation.base_salary_cents' => (string) $comp->base_salary_cents,
            'compensation.base_salary_formatted' => "{$formatted} {$currency}",
            'compensation.currency' => $currency,
            'compensation.pay_frequency' => $comp->pay_frequency ?? '',
            'compensation.overtime_rate_bps' => (string) ($comp->overtime_rate_bps ?? 0),
            'compensation.effective_from' => $comp->effective_from?->format('Y-m-d') ?? '',
            'compensation.effective_from_formatted' => $comp->effective_from?->translatedFormat('j F Y') ?? '',
        ];
    }

    private static function emptyContractFields(): array
    {
        return [
            'contract.contract_type' => '',
            'contract.contract_type_label' => '',
            'contract.work_model' => '',
            'contract.weekly_hours' => '',
            'contract.start_date' => '',
            'contract.start_date_formatted' => '',
            'contract.end_date' => '',
            'contract.end_date_formatted' => '',
            'contract.probation_end_date' => '',
            'contract.probation_end_date_formatted' => '',
            'contract.status' => '',
        ];
    }

    private static function emptyCompensationFields(): array
    {
        return [
            'compensation.base_salary_cents' => '',
            'compensation.base_salary_formatted' => '',
            'compensation.currency' => '',
            'compensation.pay_frequency' => '',
            'compensation.overtime_rate_bps' => '',
            'compensation.effective_from' => '',
            'compensation.effective_from_formatted' => '',
        ];
    }

    // --- Leave Request ---

    private static function resolveLeaveRequest(LeaveRequest $request, Company $company): array
    {
        $employee = $request->employee;

        return array_merge(
            $employee ? self::employeeFields($employee) : [],
            [
                'leave.date_from' => $request->date_from?->format('Y-m-d') ?? '',
                'leave.date_from_formatted' => $request->date_from?->translatedFormat('j F Y') ?? '',
                'leave.date_to' => $request->date_to?->format('Y-m-d') ?? '',
                'leave.date_to_formatted' => $request->date_to?->translatedFormat('j F Y') ?? '',
                'leave.days_count' => number_format(($request->days_count_hundredths ?? 0) / 100, 2),
                'leave.status' => $request->status ?? '',
                'leave.reason' => $request->reason ?? '',
                'leave.type_name' => $request->leaveType?->name ?? '',
                'leave.type_code' => $request->leaveType?->code ?? '',
                'leave.is_paid' => $request->leaveType?->is_paid ? 'Oui' : 'Non',
                'leave.reviewer_name' => $request->reviewer?->display_name ?? '',
                'leave.review_note' => $request->review_note ?? '',
                'leave.reviewed_at' => $request->reviewed_at?->format('Y-m-d') ?? '',
                'leave.reviewed_at_formatted' => $request->reviewed_at?->translatedFormat('j F Y') ?? '',
            ],
        );
    }

    private static function leaveRequestSchema(): array
    {
        return array_merge(
            [
                ['key' => 'employee.full_name', 'type' => 'string', 'label' => 'Employee name'],
                ['key' => 'employee.employee_number', 'type' => 'string', 'label' => 'Employee number'],
            ],
            [
                ['key' => 'leave.date_from', 'type' => 'date', 'label' => 'Leave start'],
                ['key' => 'leave.date_from_formatted', 'type' => 'string', 'label' => 'Leave start (formatted)'],
                ['key' => 'leave.date_to', 'type' => 'date', 'label' => 'Leave end'],
                ['key' => 'leave.date_to_formatted', 'type' => 'string', 'label' => 'Leave end (formatted)'],
                ['key' => 'leave.days_count', 'type' => 'number', 'label' => 'Days count'],
                ['key' => 'leave.status', 'type' => 'string', 'label' => 'Status'],
                ['key' => 'leave.reason', 'type' => 'string', 'label' => 'Reason'],
                ['key' => 'leave.type_name', 'type' => 'string', 'label' => 'Leave type'],
                ['key' => 'leave.type_code', 'type' => 'string', 'label' => 'Leave type code'],
                ['key' => 'leave.is_paid', 'type' => 'string', 'label' => 'Paid leave?'],
                ['key' => 'leave.reviewer_name', 'type' => 'string', 'label' => 'Reviewer'],
                ['key' => 'leave.review_note', 'type' => 'string', 'label' => 'Review note'],
                ['key' => 'leave.reviewed_at_formatted', 'type' => 'string', 'label' => 'Review date'],
            ],
        );
    }

    // --- Payroll Run ---

    private static function resolvePayrollRun(PayrollRun $run, Company $company): array
    {
        return [
            'payroll.period_start' => $run->period_start?->format('Y-m-d') ?? '',
            'payroll.period_start_formatted' => $run->period_start?->translatedFormat('j F Y') ?? '',
            'payroll.period_end' => $run->period_end?->format('Y-m-d') ?? '',
            'payroll.period_end_formatted' => $run->period_end?->translatedFormat('j F Y') ?? '',
            'payroll.status' => $run->status ?? '',
            'payroll.currency' => $run->currency ?? '',
            'payroll.total_gross_formatted' => number_format(($run->total_gross_cents ?? 0) / 100, 2, ',', ' ') . ' ' . ($run->currency ?? 'EUR'),
            'payroll.employee_count' => (string) ($run->employee_count ?? 0),
            'payroll.total_worked_hours' => number_format(($run->total_worked_minutes ?? 0) / 60, 1),
            'payroll.total_overtime_hours' => number_format(($run->total_overtime_minutes ?? 0) / 60, 1),
            'payroll.validated_at' => $run->validated_at?->format('Y-m-d') ?? '',
            'payroll.validated_at_formatted' => $run->validated_at?->translatedFormat('j F Y') ?? '',
        ];
    }

    private static function payrollRunSchema(): array
    {
        return [
            ['key' => 'payroll.period_start_formatted', 'type' => 'string', 'label' => 'Period start'],
            ['key' => 'payroll.period_end_formatted', 'type' => 'string', 'label' => 'Period end'],
            ['key' => 'payroll.status', 'type' => 'string', 'label' => 'Payroll status'],
            ['key' => 'payroll.currency', 'type' => 'string', 'label' => 'Currency'],
            ['key' => 'payroll.total_gross_formatted', 'type' => 'string', 'label' => 'Total gross'],
            ['key' => 'payroll.employee_count', 'type' => 'number', 'label' => 'Employee count'],
            ['key' => 'payroll.total_worked_hours', 'type' => 'number', 'label' => 'Total worked hours'],
            ['key' => 'payroll.total_overtime_hours', 'type' => 'number', 'label' => 'Total overtime hours'],
            ['key' => 'payroll.validated_at_formatted', 'type' => 'string', 'label' => 'Validation date'],
        ];
    }

    // --- Payroll Line ---

    private static function resolvePayrollLine(PayrollLine $line, Company $company): array
    {
        $employee = $line->employee;
        $compSnapshot = $line->compensation_snapshot ?? [];
        $currency = $compSnapshot['currency'] ?? 'EUR';

        $vars = array_merge(
            $employee ? self::employeeFields($employee) : [],
            [
                'payrollLine.worked_hours' => number_format(($line->worked_minutes ?? 0) / 60, 1),
                'payrollLine.overtime_hours' => number_format(($line->total_overtime_minutes ?? 0) / 60, 1),
                'payrollLine.leave_days' => number_format(($line->leave_days_hundredths ?? 0) / 100, 2),
                'payrollLine.paid_leave_days' => number_format(($line->paid_leave_days_hundredths ?? 0) / 100, 2),
                'payrollLine.unpaid_leave_days' => number_format(($line->unpaid_leave_days_hundredths ?? 0) / 100, 2),
                'payrollLine.base_salary_formatted' => number_format(($line->base_salary_cents ?? 0) / 100, 2, ',', ' ') . ' ' . $currency,
                'payrollLine.gross_basis_formatted' => number_format(($line->gross_basis_cents ?? 0) / 100, 2, ',', ' ') . ' ' . $currency,
                'payrollLine.currency' => $currency,
            ],
        );

        // Phase 3.2: calculation.* variables from PayrollCalculation
        $calc = $line->calculation;
        $vars = array_merge($vars, self::resolveCalculation($calc, $currency));

        // Phase 5.3: YTD cumulative variables
        $vars = array_merge($vars, self::resolveYtd($calc, $currency));

        // Contract fields for official payslip (with empty fallbacks if no contract)
        $employee = $line->employee;
        $contract = $employee?->currentContract;
        if ($contract) {
            $vars = array_merge($vars, self::contractFields($contract));
            $compensation = $contract->currentCompensation;
            if ($compensation) {
                $vars = array_merge($vars, self::compensationFields($compensation));
            } else {
                $vars = array_merge($vars, self::emptyCompensationFields());
            }
        } else {
            $vars = array_merge($vars, self::emptyContractFields(), self::emptyCompensationFields());
        }

        // Payroll run period for payslip header
        $run = $line->payrollRun;
        if ($run) {
            $vars['payroll.period_start_formatted'] = $run->period_start?->translatedFormat('F Y') ?? '';
            $vars['payroll.period_end_formatted'] = $run->period_end?->translatedFormat('j F Y') ?? '';
            $vars['payroll.period_label'] = $run->period_start?->translatedFormat('F Y') ?? '';
        }

        return $vars;
    }

    /**
     * Resolve calculation.* variables from a PayrollCalculation.
     * Returns all variables as empty strings if no calculation exists.
     */
    private static function resolveCalculation(?PayrollCalculation $calc, string $currency): array
    {
        $fmt = fn (?int $cents) => $cents !== null
            ? number_format($cents / 100, 2, ',', ' ') . ' ' . $currency
            : '';

        $vars = [
            'calculation.gross_total_cents' => (string) ($calc?->gross_total_cents ?? ''),
            'calculation.gross_total_formatted' => $fmt($calc?->gross_total_cents),
            'calculation.contributions_employee_cents' => (string) ($calc?->contributions_employee_cents ?? ''),
            'calculation.contributions_employee_formatted' => $fmt($calc?->contributions_employee_cents),
            'calculation.contributions_employer_cents' => (string) ($calc?->contributions_employer_cents ?? ''),
            'calculation.contributions_employer_formatted' => $fmt($calc?->contributions_employer_cents),
            'calculation.total_cost_employer_cents' => (string) ($calc?->total_cost_employer_cents ?? ''),
            'calculation.total_cost_employer_formatted' => $fmt($calc?->total_cost_employer_cents),
            'calculation.taxable_income_cents' => (string) ($calc?->taxable_income_cents ?? ''),
            'calculation.taxable_income_formatted' => $fmt($calc?->taxable_income_cents),
            'calculation.tax_cents' => (string) ($calc?->tax_cents ?? ''),
            'calculation.tax_formatted' => $fmt($calc?->tax_cents),
            'calculation.net_before_tax_cents' => (string) ($calc?->net_before_tax_cents ?? ''),
            'calculation.net_before_tax_formatted' => $fmt($calc?->net_before_tax_cents),
            'calculation.net_payable_cents' => (string) ($calc?->net_payable_cents ?? ''),
            'calculation.net_payable_formatted' => $fmt($calc?->net_payable_cents),
            'calculation.benefits_cents' => (string) ($calc?->benefits_cents ?? ''),
            'calculation.benefits_formatted' => $fmt($calc?->benefits_cents),
            'calculation.deductions_cents' => (string) ($calc?->deductions_cents ?? ''),
            'calculation.deductions_formatted' => $fmt($calc?->deductions_cents),
            'calculation.plafond_ss_formatted' => $fmt($calc?->plafond_ss_monthly_cents),
            'calculation.rule_version' => (string) ($calc?->rule_version ?? ''),
            'calculation.status' => (string) ($calc?->status ?? ''),
        ];

        // Tax rate from tax_breakdown
        $taxBreakdown = $calc?->tax_breakdown ?? [];
        $taxRateBps = $taxBreakdown['tax_rate_bps'] ?? null;
        $vars['calculation.tax_rate_bps'] = (string) ($taxRateBps ?? '');
        $vars['calculation.tax_rate_percent'] = $taxRateBps !== null
            ? number_format($taxRateBps / 100, 2) . '%'
            : '';

        // Net social (Phase 5.2 — ADR-513)
        $vars['calculation.net_social_cents'] = (string) ($calc?->net_social_cents ?? '');
        $vars['calculation.net_social_formatted'] = $fmt($calc?->net_social_cents);

        // Relief / Allègements (Phase 5.2 — stub)
        $reliefLines = $calc?->relief_lines ?? [];
        $vars['calculation.relief_total_formatted'] = $fmt($reliefLines['total_employer_relief_cents'] ?? 0);
        $vars['calculation.relief_line_count'] = (string) count($reliefLines['lines'] ?? []);

        // Contribution lines (indexed: calculation.contribution_line_1_code, etc.)
        $contribData = $calc?->contribution_lines ?? [];
        $lines = $contribData['lines'] ?? $contribData;
        $n = 0;
        foreach ($lines as $cl) {
            $n++;
            $vars["calculation.contribution_line_{$n}_code"] = (string) ($cl['code'] ?? '');
            $vars["calculation.contribution_line_{$n}_label"] = (string) ($cl['label'] ?? $cl['code'] ?? '');
            $vars["calculation.contribution_line_{$n}_base_formatted"] = isset($cl['base_cents']) ? $fmt($cl['base_cents']) : '';
            $vars["calculation.contribution_line_{$n}_employee_rate"] = isset($cl['employee_rate_bps']) ? number_format($cl['employee_rate_bps'] / 100, 2) . '%' : '';
            $vars["calculation.contribution_line_{$n}_employer_rate"] = isset($cl['employer_rate_bps']) ? number_format($cl['employer_rate_bps'] / 100, 2) . '%' : '';
            $vars["calculation.contribution_line_{$n}_employee_formatted"] = isset($cl['employee_cents']) ? $fmt($cl['employee_cents']) : '';
            $vars["calculation.contribution_line_{$n}_employer_formatted"] = isset($cl['employer_cents']) ? $fmt($cl['employer_cents']) : '';
            $vars["calculation.contribution_line_{$n}_bulletin_group"] = (string) ($cl['bulletin_group'] ?? '');
        }
        $vars['calculation.contribution_line_count'] = (string) count($lines);

        // Pad empty slots up to 25 lines (20 current + room for future)
        $maxSlots = max($n, 25);
        for ($i = $n + 1; $i <= $maxSlots; $i++) {
            $vars["calculation.contribution_line_{$i}_code"] = '';
            $vars["calculation.contribution_line_{$i}_label"] = '';
            $vars["calculation.contribution_line_{$i}_base_formatted"] = '';
            $vars["calculation.contribution_line_{$i}_employee_rate"] = '';
            $vars["calculation.contribution_line_{$i}_employer_rate"] = '';
            $vars["calculation.contribution_line_{$i}_employee_formatted"] = '';
            $vars["calculation.contribution_line_{$i}_employer_formatted"] = '';
            $vars["calculation.contribution_line_{$i}_bulletin_group"] = '';
        }

        // Bulletin group subtotals (Phase 5.3 — grouped by URSSAF categories)
        $vars = array_merge($vars, self::resolveBulletinGroups($lines, $fmt));

        return $vars;
    }

    /**
     * Resolve bulletin group subtotals for the official FR payslip.
     * Groups contribution lines by bulletin_group (7 URSSAF groups).
     * For each group: label, employee_total, employer_total, line_count, and up to 10 indexed lines.
     */
    private static function resolveBulletinGroups(array $lines, \Closure $fmt): array
    {
        $vars = [];
        $grouped = [];

        foreach ($lines as $cl) {
            $group = $cl['bulletin_group'] ?? 'autres_employeur';
            $grouped[$group][] = $cl;
        }

        foreach (BulletinGroup::DISPLAY_ORDER as $group) {
            $groupLines = $grouped[$group] ?? [];
            $empTotal = 0;
            $erTotal = 0;

            foreach ($groupLines as $gl) {
                $empTotal += (int) ($gl['employee_cents'] ?? 0);
                $erTotal += (int) ($gl['employer_cents'] ?? 0);
            }

            $vars["bulletin_group.{$group}.label"] = BulletinGroup::label($group);
            $vars["bulletin_group.{$group}.employee_total_formatted"] = $fmt($empTotal);
            $vars["bulletin_group.{$group}.employer_total_formatted"] = $fmt($erTotal);
            $vars["bulletin_group.{$group}.line_count"] = (string) count($groupLines);

            // Indexed lines within each group (up to 10)
            $gn = 0;
            foreach ($groupLines as $gl) {
                $gn++;
                $vars["bulletin_group.{$group}.line_{$gn}_label"] = (string) ($gl['label'] ?? '');
                $vars["bulletin_group.{$group}.line_{$gn}_base_formatted"] = isset($gl['base_cents']) ? $fmt($gl['base_cents']) : '';
                $vars["bulletin_group.{$group}.line_{$gn}_employee_rate"] = isset($gl['employee_rate_bps']) ? number_format($gl['employee_rate_bps'] / 100, 2) . '%' : '';
                $vars["bulletin_group.{$group}.line_{$gn}_employer_rate"] = isset($gl['employer_rate_bps']) ? number_format($gl['employer_rate_bps'] / 100, 2) . '%' : '';
                $vars["bulletin_group.{$group}.line_{$gn}_employee_formatted"] = isset($gl['employee_cents']) ? $fmt($gl['employee_cents']) : '';
                $vars["bulletin_group.{$group}.line_{$gn}_employer_formatted"] = isset($gl['employer_cents']) ? $fmt($gl['employer_cents']) : '';
            }
            // Pad up to 10 lines per group
            for ($j = $gn + 1; $j <= 10; $j++) {
                $vars["bulletin_group.{$group}.line_{$j}_label"] = '';
                $vars["bulletin_group.{$group}.line_{$j}_base_formatted"] = '';
                $vars["bulletin_group.{$group}.line_{$j}_employee_rate"] = '';
                $vars["bulletin_group.{$group}.line_{$j}_employer_rate"] = '';
                $vars["bulletin_group.{$group}.line_{$j}_employee_formatted"] = '';
                $vars["bulletin_group.{$group}.line_{$j}_employer_formatted"] = '';
            }
        }

        return $vars;
    }

    /**
     * Resolve YTD variables from PayrollYtdSnapshot (Phase 5.3).
     */
    private static function resolveYtd(?PayrollCalculation $calc, string $currency): array
    {
        $fmt = fn (?int $cents) => $cents !== null
            ? number_format($cents / 100, 2, ',', ' ') . ' ' . $currency
            : '';

        $ytd = $calc?->ytdSnapshot;

        return [
            'ytd.gross_total_formatted' => $fmt($ytd?->ytd_gross_total_cents),
            'ytd.contributions_employee_formatted' => $fmt($ytd?->ytd_contributions_employee_cents),
            'ytd.contributions_employer_formatted' => $fmt($ytd?->ytd_contributions_employer_cents),
            'ytd.tax_formatted' => $fmt($ytd?->ytd_tax_cents),
            'ytd.net_before_tax_formatted' => $fmt($ytd?->ytd_net_before_tax_cents),
            'ytd.net_payable_formatted' => $fmt($ytd?->ytd_net_payable_cents),
            'ytd.total_cost_employer_formatted' => $fmt($ytd?->ytd_total_cost_employer_cents),
            'ytd.months_count' => (string) count($ytd?->months_included ?? []),
        ];
    }

    private static function payrollLineSchema(): array
    {
        return array_merge(
            [
                ['key' => 'company.siret', 'type' => 'string', 'label' => 'SIRET'],
                ['key' => 'company.naf_code', 'type' => 'string', 'label' => 'Code NAF/APE'],
                ['key' => 'employee.full_name', 'type' => 'string', 'label' => 'Employee name'],
                ['key' => 'employee.employee_number', 'type' => 'string', 'label' => 'Employee number'],
                ['key' => 'employee.hire_date_formatted', 'type' => 'string', 'label' => 'Hire date'],
                ['key' => 'contract.contract_type_label', 'type' => 'string', 'label' => 'Contract type'],
                ['key' => 'contract.weekly_hours', 'type' => 'number', 'label' => 'Weekly hours'],
                ['key' => 'compensation.base_salary_formatted', 'type' => 'string', 'label' => 'Base salary'],
                ['key' => 'compensation.pay_frequency', 'type' => 'string', 'label' => 'Pay frequency'],
            ],
            [
                ['key' => 'payroll.period_label', 'type' => 'string', 'label' => 'Period label'],
                ['key' => 'payrollLine.worked_hours', 'type' => 'number', 'label' => 'Worked hours'],
                ['key' => 'payrollLine.overtime_hours', 'type' => 'number', 'label' => 'Overtime hours'],
                ['key' => 'payrollLine.leave_days', 'type' => 'number', 'label' => 'Leave days'],
                ['key' => 'payrollLine.paid_leave_days', 'type' => 'number', 'label' => 'Paid leave days'],
                ['key' => 'payrollLine.unpaid_leave_days', 'type' => 'number', 'label' => 'Unpaid leave days'],
                ['key' => 'payrollLine.base_salary_formatted', 'type' => 'string', 'label' => 'Base salary'],
                ['key' => 'payrollLine.gross_basis_formatted', 'type' => 'string', 'label' => 'Gross basis'],
                ['key' => 'payrollLine.currency', 'type' => 'string', 'label' => 'Currency'],
            ],
            [
                ['key' => 'calculation.gross_total_formatted', 'type' => 'string', 'label' => 'Gross total'],
                ['key' => 'calculation.contributions_employee_formatted', 'type' => 'string', 'label' => 'Employee contributions'],
                ['key' => 'calculation.contributions_employer_formatted', 'type' => 'string', 'label' => 'Employer contributions'],
                ['key' => 'calculation.tax_formatted', 'type' => 'string', 'label' => 'Tax (PAS)'],
                ['key' => 'calculation.tax_rate_percent', 'type' => 'string', 'label' => 'Tax rate'],
                ['key' => 'calculation.net_before_tax_formatted', 'type' => 'string', 'label' => 'Net before tax'],
                ['key' => 'calculation.net_payable_formatted', 'type' => 'string', 'label' => 'Net payable'],
                ['key' => 'calculation.net_social_formatted', 'type' => 'string', 'label' => 'Net social'],
                ['key' => 'calculation.total_cost_employer_formatted', 'type' => 'string', 'label' => 'Total employer cost'],
                ['key' => 'calculation.relief_total_formatted', 'type' => 'string', 'label' => 'Total employer relief'],
                ['key' => 'calculation.plafond_ss_formatted', 'type' => 'string', 'label' => 'Plafond SS'],
                ['key' => 'calculation.rule_version', 'type' => 'string', 'label' => 'Rule version'],
                ['key' => 'calculation.contribution_line_count', 'type' => 'number', 'label' => 'Contribution lines count'],
            ],
            [
                ['key' => 'ytd.gross_total_formatted', 'type' => 'string', 'label' => 'YTD gross total'],
                ['key' => 'ytd.contributions_employee_formatted', 'type' => 'string', 'label' => 'YTD employee contributions'],
                ['key' => 'ytd.contributions_employer_formatted', 'type' => 'string', 'label' => 'YTD employer contributions'],
                ['key' => 'ytd.tax_formatted', 'type' => 'string', 'label' => 'YTD tax'],
                ['key' => 'ytd.net_payable_formatted', 'type' => 'string', 'label' => 'YTD net payable'],
                ['key' => 'ytd.months_count', 'type' => 'number', 'label' => 'YTD months count'],
            ],
        );
    }

    // --- FieldValues (dynamic) ---

    private static function resolveFieldValues(Model $subject): array
    {
        $values = FieldValue::where('model_type', get_class($subject))
            ->where('model_id', $subject->getKey())
            ->with('definition')
            ->get();

        $result = [];
        foreach ($values as $fv) {
            $code = $fv->definition?->code;
            if ($code) {
                $val = $fv->value;
                $result["field.{$code}"] = is_array($val) ? json_encode($val) : (string) ($val ?? '');
            }
        }

        return $result;
    }
}
