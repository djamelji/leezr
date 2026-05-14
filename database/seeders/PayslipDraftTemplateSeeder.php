<?php

namespace Database\Seeders;

use App\Core\Documents\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds a platform-level payslip draft template for the FR market.
 *
 * DISCLAIMER: This template produces DRAFT documents only.
 * It does NOT constitute an official payslip under French labor law.
 */
class PayslipDraftTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Skip if already seeded (idempotent)
        $existing = DocumentTemplate::where('company_id', null)
            ->where('code', 'payslip_draft_fr')
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return;
        }

        DocumentTemplate::create([
            'company_id' => null,
            'code' => 'payslip_draft_fr',
            'name' => 'Fiche de paie — BROUILLON',
            'description' => 'Fiche de paie brouillon (FR). Document non officiel, à titre indicatif uniquement.',
            'subject_type' => 'payroll_line',
            'body_html' => self::bodyHtml(),
            'header_html' => self::headerHtml(),
            'footer_html' => self::footerHtml(),
            'variables_schema' => self::variablesSchema(),
            'version' => 1,
            'is_system' => true,
            'is_active' => true,
            'paper_size' => 'a4',
            'paper_orientation' => 'portrait',
        ]);
    }

    private static function headerHtml(): string
    {
        return <<<'HTML'
<div style="text-align: center; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0; font-size: 18px; color: #333;">{{ company.name }}</h2>
    <p style="margin: 4px 0 0; font-size: 11px; color: #666;">{{ company.legal_status }}</p>
</div>
HTML;
    }

    private static function footerHtml(): string
    {
        return <<<'HTML'
<div style="border-top: 1px solid #ccc; padding-top: 6px; text-align: center; font-size: 9px; color: #999;">
    BROUILLON — Document non officiel, à titre indicatif uniquement. Ne constitue pas une fiche de paie au sens du Code du travail.
    <br>Généré le {{ today_formatted }} — {{ calculation.rule_version }}
</div>
HTML;
    }

    private static function bodyHtml(): string
    {
        return <<<'HTML'
<!-- Watermark -->
<div style="position: fixed; top: 35%; left: 10%; transform: rotate(-30deg); font-size: 80px; color: rgba(0,0,0,0.06); font-weight: bold; z-index: -1; pointer-events: none;">
    BROUILLON
</div>

<div style="font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333;">

    <!-- Title -->
    <h3 style="text-align: center; margin: 0 0 16px; font-size: 14px; color: #555;">
        Fiche de paie — BROUILLON
    </h3>

    <!-- Period + Employee -->
    <table style="width: 100%; margin-bottom: 16px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <strong>Salarié</strong><br>
                {{ employee.full_name }}<br>
                N° {{ employee.employee_number }}<br>
                Embauché le {{ employee.hire_date_formatted }}
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <strong>Période</strong><br>
                {{ payrollLine.currency }}<br>
                Heures travaillées : {{ payrollLine.worked_hours }}<br>
                Heures sup. : {{ payrollLine.overtime_hours }}
            </td>
        </tr>
    </table>

    <!-- Earnings -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ddd;">Rémunération brute</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Montant</th>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">Salaire de base</td>
            <td style="text-align: right; padding: 4px 6px;">{{ payrollLine.base_salary_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">Base brute (base + heures sup. − absences)</td>
            <td style="text-align: right; padding: 4px 6px;">{{ payrollLine.gross_basis_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">Avantages imposables</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.benefits_formatted }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 1px solid #999;">
            <td style="padding: 6px;">Total brut</td>
            <td style="text-align: right; padding: 6px;">{{ calculation.gross_total_formatted }}</td>
        </tr>
    </table>

    <!-- Contributions -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ddd;">Cotisations</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Base</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Taux sal.</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Part sal.</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Taux pat.</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Part pat.</th>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_1_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_1_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_1_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_1_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_1_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_1_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_2_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_2_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_2_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_2_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_2_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_2_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_3_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_3_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_3_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_3_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_3_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_3_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_4_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_4_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_4_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_4_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_4_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_4_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_5_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_5_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_5_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_5_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_5_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_5_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_6_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_6_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_6_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_6_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_6_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_6_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_7_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_7_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_7_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_7_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_7_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_7_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_8_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_8_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_8_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_8_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_8_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_8_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">{{ calculation.contribution_line_9_label }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_9_base_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_9_employee_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_9_employee_formatted }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_9_employer_rate }}</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.contribution_line_9_employer_formatted }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 1px solid #999;">
            <td style="padding: 6px;">Total cotisations</td>
            <td></td>
            <td></td>
            <td style="text-align: right; padding: 6px;">{{ calculation.contributions_employee_formatted }}</td>
            <td></td>
            <td style="text-align: right; padding: 6px;">{{ calculation.contributions_employer_formatted }}</td>
        </tr>
    </table>

    <!-- Tax (PAS) -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ddd;">Prélèvement à la source</th>
            <th style="text-align: right; padding: 6px; border-bottom: 1px solid #ddd;">Montant</th>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">Revenu imposable</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.taxable_income_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px;">Taux PAS</td>
            <td style="text-align: right; padding: 4px 6px;">{{ calculation.tax_rate_percent }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 1px solid #999;">
            <td style="padding: 6px;">Impôt sur le revenu (PAS)</td>
            <td style="text-align: right; padding: 6px;">{{ calculation.tax_formatted }}</td>
        </tr>
    </table>

    <!-- Net Summary -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; background: #f9f9f9;">
        <tr>
            <td style="padding: 6px; font-weight: bold;">Net avant impôt</td>
            <td style="text-align: right; padding: 6px; font-weight: bold;">{{ calculation.net_before_tax_formatted }}</td>
        </tr>
        <tr style="font-size: 14px; background: #e8f5e9;">
            <td style="padding: 8px; font-weight: bold;">NET À PAYER</td>
            <td style="text-align: right; padding: 8px; font-weight: bold;">{{ calculation.net_payable_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 6px; color: #666;">Coût employeur total</td>
            <td style="text-align: right; padding: 6px; color: #666;">{{ calculation.total_cost_employer_formatted }}</td>
        </tr>
    </table>

    <!-- Plafond SS -->
    <p style="font-size: 9px; color: #999;">
        Plafond SS mensuel : {{ calculation.plafond_ss_formatted }} — Version règles : {{ calculation.rule_version }}
    </p>

</div>
HTML;
    }

    private static function variablesSchema(): array
    {
        return [
            ['key' => 'company.name', 'type' => 'string', 'label' => 'Company name'],
            ['key' => 'company.legal_status', 'type' => 'string', 'label' => 'Legal status'],
            ['key' => 'employee.full_name', 'type' => 'string', 'label' => 'Employee name'],
            ['key' => 'employee.employee_number', 'type' => 'string', 'label' => 'Employee number'],
            ['key' => 'employee.hire_date_formatted', 'type' => 'string', 'label' => 'Hire date'],
            ['key' => 'payrollLine.worked_hours', 'type' => 'number', 'label' => 'Worked hours'],
            ['key' => 'payrollLine.overtime_hours', 'type' => 'number', 'label' => 'Overtime hours'],
            ['key' => 'payrollLine.base_salary_formatted', 'type' => 'string', 'label' => 'Base salary'],
            ['key' => 'payrollLine.gross_basis_formatted', 'type' => 'string', 'label' => 'Gross basis'],
            ['key' => 'payrollLine.currency', 'type' => 'string', 'label' => 'Currency'],
            ['key' => 'calculation.gross_total_formatted', 'type' => 'string', 'label' => 'Gross total'],
            ['key' => 'calculation.benefits_formatted', 'type' => 'string', 'label' => 'Benefits'],
            ['key' => 'calculation.contributions_employee_formatted', 'type' => 'string', 'label' => 'Employee contributions'],
            ['key' => 'calculation.contributions_employer_formatted', 'type' => 'string', 'label' => 'Employer contributions'],
            ['key' => 'calculation.taxable_income_formatted', 'type' => 'string', 'label' => 'Taxable income'],
            ['key' => 'calculation.tax_rate_percent', 'type' => 'string', 'label' => 'Tax rate'],
            ['key' => 'calculation.tax_formatted', 'type' => 'string', 'label' => 'Tax amount'],
            ['key' => 'calculation.net_before_tax_formatted', 'type' => 'string', 'label' => 'Net before tax'],
            ['key' => 'calculation.net_payable_formatted', 'type' => 'string', 'label' => 'Net payable'],
            ['key' => 'calculation.total_cost_employer_formatted', 'type' => 'string', 'label' => 'Total employer cost'],
            ['key' => 'calculation.plafond_ss_formatted', 'type' => 'string', 'label' => 'Plafond SS'],
            ['key' => 'calculation.rule_version', 'type' => 'string', 'label' => 'Rule version'],
            ['key' => 'calculation.contribution_line_1_label', 'type' => 'string', 'label' => 'Contribution 1 label'],
            ['key' => 'calculation.contribution_line_1_base_formatted', 'type' => 'string', 'label' => 'Contribution 1 base'],
            ['key' => 'calculation.contribution_line_1_employee_rate', 'type' => 'string', 'label' => 'Contribution 1 employee rate'],
            ['key' => 'calculation.contribution_line_1_employee_formatted', 'type' => 'string', 'label' => 'Contribution 1 employee amount'],
            ['key' => 'calculation.contribution_line_1_employer_rate', 'type' => 'string', 'label' => 'Contribution 1 employer rate'],
            ['key' => 'calculation.contribution_line_1_employer_formatted', 'type' => 'string', 'label' => 'Contribution 1 employer amount'],
            ['key' => 'today_formatted', 'type' => 'string', 'label' => 'Today date'],
        ];
    }
}
