<?php

namespace Database\Seeders;

use App\Core\Documents\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the official French payslip template (bulletin clarifié FR).
 *
 * Structure follows the arrêté du 25 février 2016 modifié, with 7 bulletin
 * groups (santé, AT/MP, retraite, famille, chômage, CSG/CRDS, autres
 * employeur), net social, PAS, YTD cumuls, and legal mentions.
 */
class PayslipOfficialFrTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent — skip if already seeded
        $existing = DocumentTemplate::where('company_id', null)
            ->where('code', 'payslip_official_fr')
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return;
        }

        DocumentTemplate::create([
            'company_id' => null,
            'code' => 'payslip_official_fr',
            'name' => 'Bulletin de paie — officiel FR',
            'description' => 'Bulletin de paie clarifié conforme à l\'arrêté du 25/02/2016 modifié. 7 rubriques réglementaires, net social, PAS, cumuls annuels.',
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

    // ─────────────────────────────────────────────────────────
    // Header
    // ─────────────────────────────────────────────────────────

    private static function headerHtml(): string
    {
        return <<<'HTML'
<div style="border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 16px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top; width: 60%;">
                <strong style="font-size: 14px;">{{ company.name }}</strong><br>
                <span style="font-size: 10px; color: #555;">{{ company.legal_status }}</span><br>
                <span style="font-size: 10px; color: #555;">SIRET : {{ company.siret }} — NAF : {{ company.naf_code }}</span>
            </td>
            <td style="vertical-align: top; width: 40%; text-align: right;">
                <span style="font-size: 10px; color: #555;">Période : {{ payroll.period_label }}</span>
            </td>
        </tr>
    </table>
</div>
HTML;
    }

    // ─────────────────────────────────────────────────────────
    // Footer
    // ─────────────────────────────────────────────────────────

    private static function footerHtml(): string
    {
        return <<<'HTML'
<div style="border-top: 1px solid #ccc; padding-top: 8px; font-size: 8px; color: #777; line-height: 1.5;">
    <p style="margin: 0 0 4px;">
        Ce bulletin de paie doit être conservé sans limitation de durée (art. L. 3243-4 du Code du travail).
    </p>
    <p style="margin: 0 0 4px;">
        En cas de contestation des sommes indiquées, le salarié dispose d'un délai de 3 ans (art. L. 3245-1).
    </p>
    <p style="margin: 0 0 4px;">
        Calcul indicatif — validation expert-comptable requise avant mise en conformité.
    </p>
    <p style="margin: 0; color: #999;">
        Plafond SS mensuel : {{ calculation.plafond_ss_formatted }} — Version règles : {{ calculation.rule_version }} — Généré le {{ today_formatted }}
    </p>
</div>
HTML;
    }

    // ─────────────────────────────────────────────────────────
    // Body
    // ─────────────────────────────────────────────────────────

    private static function bodyHtml(): string
    {
        // Build the 7 bulletin group sections
        $groups = ['sante', 'at_mp', 'retraite', 'famille', 'chomage', 'csg_crds', 'autres_employeur'];
        $groupRows = '';

        foreach ($groups as $g) {
            $groupRows .= self::bulletinGroupHtml($g);
        }

        return <<<HTML
<div style="font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #333; line-height: 1.4;">

    <!-- Title -->
    <h2 style="text-align: center; margin: 0 0 14px; font-size: 16px; letter-spacing: 1px;">
        BULLETIN DE PAIE
    </h2>

    <!-- ─── Employee info ─── -->
    <table style="width: 100%; margin-bottom: 14px; border-collapse: collapse;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding: 4px 6px; border: 1px solid #ddd; background: #fafafa;">
                <strong>Salarié</strong><br>
                {{ employee.full_name }}<br>
                N° {{ employee.employee_number }}<br>
                Embauché le {{ employee.hire_date_formatted }}<br>
                Contrat : {{ contract.contract_type_label }} — {{ contract.weekly_hours }} h/sem.
            </td>
            <td style="width: 45%; vertical-align: top; padding: 4px 6px; border: 1px solid #ddd; background: #fafafa; text-align: right;">
                <strong>Période</strong><br>
                {{ payroll.period_label }}<br>
                Heures travaillées : {{ payrollLine.worked_hours }}<br>
                Heures sup. : {{ payrollLine.overtime_hours }}<br>
                Devise : {{ payrollLine.currency }}
            </td>
        </tr>
    </table>

    <!-- ─── Section 1 — Rémunération brute ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;" colspan="2">
                1. Rémunération brute
            </th>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Salaire de base</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ payrollLine.base_salary_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Base brute (base + heures sup. − absences)</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ payrollLine.gross_basis_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Avantages imposables</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ calculation.benefits_formatted }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 2px solid #999;">
            <td style="padding: 5px 6px;">Total brut</td>
            <td style="text-align: right; padding: 5px 6px;">{{ calculation.gross_total_formatted }}</td>
        </tr>
    </table>

    <!-- ─── Section 2 — Cotisations et contributions sociales ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;">
                2. Cotisations et contributions sociales
            </th>
            <th style="text-align: right; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 9px;">Base</th>
            <th style="text-align: right; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 9px;">Taux sal.</th>
            <th style="text-align: right; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 9px;">Part sal.</th>
            <th style="text-align: right; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 9px;">Taux pat.</th>
            <th style="text-align: right; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 9px;">Part pat.</th>
        </tr>

{$groupRows}

        <!-- Total cotisations -->
        <tr style="font-weight: bold; border-top: 2px solid #999;">
            <td style="padding: 5px 6px;">Total cotisations et contributions</td>
            <td></td>
            <td></td>
            <td style="text-align: right; padding: 5px 6px;">{{ calculation.contributions_employee_formatted }}</td>
            <td></td>
            <td style="text-align: right; padding: 5px 6px;">{{ calculation.contributions_employer_formatted }}</td>
        </tr>
    </table>

    <!-- ─── Section 3 — Prélèvement à la source ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;" colspan="2">
                3. Prélèvement à la source (PAS)
            </th>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Revenu net imposable</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ calculation.taxable_income_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Taux PAS</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ calculation.tax_rate_percent }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 2px solid #999;">
            <td style="padding: 5px 6px;">Impôt sur le revenu prélevé à la source</td>
            <td style="text-align: right; padding: 5px 6px;">{{ calculation.tax_formatted }}</td>
        </tr>
    </table>

    <!-- ─── Section 4 — Net social ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;" colspan="2">
                4. Montant net social
            </th>
        </tr>
        <tr style="font-weight: bold;">
            <td style="padding: 5px 6px;">Net social</td>
            <td style="text-align: right; padding: 5px 6px;">{{ calculation.net_social_formatted }}</td>
        </tr>
        <tr>
            <td colspan="2" style="padding: 3px 6px; font-size: 8px; color: #888; font-style: italic;">
                Montant net social — art. L. 3243-2 du Code du travail
            </td>
        </tr>
    </table>

    <!-- ─── Section 5 — Net à payer ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;" colspan="2">
                5. Net à payer
            </th>
        </tr>
        <tr>
            <td style="padding: 4px 6px; border-bottom: 1px solid #eee;">Net avant impôt sur le revenu</td>
            <td style="text-align: right; padding: 4px 6px; border-bottom: 1px solid #eee;">{{ calculation.net_before_tax_formatted }}</td>
        </tr>
        <tr style="font-size: 14px; font-weight: bold; background: #e8f5e9;">
            <td style="padding: 10px 6px;">NET À PAYER</td>
            <td style="text-align: right; padding: 10px 6px;">{{ calculation.net_payable_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px; border-top: 1px solid #eee; color: #666;">Coût employeur total</td>
            <td style="text-align: right; padding: 4px 6px; border-top: 1px solid #eee; color: #666;">{{ calculation.total_cost_employer_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 6px; border-top: 1px solid #eee; color: #666;">Allègements de cotisations employeur</td>
            <td style="text-align: right; padding: 4px 6px; border-top: 1px solid #eee; color: #666;">{{ calculation.relief_total_formatted }}</td>
        </tr>
    </table>

    <!-- ─── Section 6 — Cumuls annuels (YTD) ─── -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <th style="text-align: left; padding: 5px 6px; border-bottom: 1px solid #ccc; font-size: 11px;" colspan="2">
                6. Cumuls annuels ({{ ytd.months_count }} mois)
            </th>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Total brut</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ ytd.gross_total_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Cotisations salariales</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ ytd.contributions_employee_formatted }}</td>
        </tr>
        <tr>
            <td style="padding: 3px 6px; border-bottom: 1px solid #eee;">Impôt sur le revenu (PAS)</td>
            <td style="text-align: right; padding: 3px 6px; border-bottom: 1px solid #eee;">{{ ytd.tax_formatted }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 1px solid #999;">
            <td style="padding: 5px 6px;">Net à payer cumulé</td>
            <td style="text-align: right; padding: 5px 6px;">{{ ytd.net_payable_formatted }}</td>
        </tr>
    </table>

</div>
HTML;
    }

    // ─────────────────────────────────────────────────────────
    // Bulletin group HTML generator (3 lines per group)
    // ─────────────────────────────────────────────────────────

    private static function bulletinGroupHtml(string $group): string
    {
        $rows = '';

        // Group header
        $rows .= <<<HTML

        <!-- Group: {$group} -->
        <tr style="background: #f0f0f0; font-weight: bold;">
            <td colspan="6" style="padding: 4px 6px; font-size: 10px;">{{ bulletin_group.{$group}.label }}</td>
        </tr>
HTML;

        // 3 lines per group
        for ($n = 1; $n <= 3; $n++) {
            $rows .= <<<HTML

        <tr>
            <td style="padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_label }}</td>
            <td style="text-align: right; padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_base_formatted }}</td>
            <td style="text-align: right; padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_employee_rate }}</td>
            <td style="text-align: right; padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_employee_formatted }}</td>
            <td style="text-align: right; padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_employer_rate }}</td>
            <td style="text-align: right; padding: 2px 6px; border-bottom: 1px solid #eee;">{{ bulletin_group.{$group}.line_{$n}_employer_formatted }}</td>
        </tr>
HTML;
        }

        // Group subtotal
        $rows .= <<<HTML

        <tr style="font-weight: bold; background: #fafafa; border-top: 1px solid #ccc;">
            <td style="padding: 3px 6px; font-size: 9px;">Total {{ bulletin_group.{$group}.label }}</td>
            <td></td>
            <td></td>
            <td style="text-align: right; padding: 3px 6px;">{{ bulletin_group.{$group}.employee_total_formatted }}</td>
            <td></td>
            <td style="text-align: right; padding: 3px 6px;">{{ bulletin_group.{$group}.employer_total_formatted }}</td>
        </tr>
HTML;

        return $rows;
    }

    // ─────────────────────────────────────────────────────────
    // Variables schema
    // ─────────────────────────────────────────────────────────

    private static function variablesSchema(): array
    {
        $schema = [
            // Company
            ['key' => 'company.name', 'type' => 'string', 'label' => 'Company name'],
            ['key' => 'company.legal_status', 'type' => 'string', 'label' => 'Legal status'],
            ['key' => 'company.siret', 'type' => 'string', 'label' => 'SIRET'],
            ['key' => 'company.naf_code', 'type' => 'string', 'label' => 'Code NAF/APE'],

            // Employee
            ['key' => 'employee.full_name', 'type' => 'string', 'label' => 'Employee name'],
            ['key' => 'employee.employee_number', 'type' => 'string', 'label' => 'Employee number'],
            ['key' => 'employee.hire_date_formatted', 'type' => 'string', 'label' => 'Hire date'],

            // Contract
            ['key' => 'contract.contract_type_label', 'type' => 'string', 'label' => 'Contract type'],
            ['key' => 'contract.weekly_hours', 'type' => 'number', 'label' => 'Weekly hours'],

            // Payroll / Period
            ['key' => 'payroll.period_label', 'type' => 'string', 'label' => 'Period label'],
            ['key' => 'payrollLine.worked_hours', 'type' => 'number', 'label' => 'Worked hours'],
            ['key' => 'payrollLine.overtime_hours', 'type' => 'number', 'label' => 'Overtime hours'],
            ['key' => 'payrollLine.base_salary_formatted', 'type' => 'string', 'label' => 'Base salary'],
            ['key' => 'payrollLine.gross_basis_formatted', 'type' => 'string', 'label' => 'Gross basis'],
            ['key' => 'payrollLine.currency', 'type' => 'string', 'label' => 'Currency'],

            // Calculation
            ['key' => 'calculation.gross_total_formatted', 'type' => 'string', 'label' => 'Gross total'],
            ['key' => 'calculation.benefits_formatted', 'type' => 'string', 'label' => 'Benefits'],
            ['key' => 'calculation.contributions_employee_formatted', 'type' => 'string', 'label' => 'Total employee contributions'],
            ['key' => 'calculation.contributions_employer_formatted', 'type' => 'string', 'label' => 'Total employer contributions'],
            ['key' => 'calculation.taxable_income_formatted', 'type' => 'string', 'label' => 'Taxable income'],
            ['key' => 'calculation.tax_rate_percent', 'type' => 'string', 'label' => 'PAS tax rate'],
            ['key' => 'calculation.tax_formatted', 'type' => 'string', 'label' => 'PAS tax amount'],
            ['key' => 'calculation.net_social_formatted', 'type' => 'string', 'label' => 'Net social'],
            ['key' => 'calculation.net_before_tax_formatted', 'type' => 'string', 'label' => 'Net before tax'],
            ['key' => 'calculation.net_payable_formatted', 'type' => 'string', 'label' => 'Net payable'],
            ['key' => 'calculation.total_cost_employer_formatted', 'type' => 'string', 'label' => 'Total employer cost'],
            ['key' => 'calculation.relief_total_formatted', 'type' => 'string', 'label' => 'Total employer relief'],
            ['key' => 'calculation.plafond_ss_formatted', 'type' => 'string', 'label' => 'Plafond SS mensuel'],
            ['key' => 'calculation.rule_version', 'type' => 'string', 'label' => 'Rule version'],

            // YTD
            ['key' => 'ytd.gross_total_formatted', 'type' => 'string', 'label' => 'YTD gross total'],
            ['key' => 'ytd.contributions_employee_formatted', 'type' => 'string', 'label' => 'YTD employee contributions'],
            ['key' => 'ytd.tax_formatted', 'type' => 'string', 'label' => 'YTD tax'],
            ['key' => 'ytd.net_payable_formatted', 'type' => 'string', 'label' => 'YTD net payable'],
            ['key' => 'ytd.months_count', 'type' => 'number', 'label' => 'YTD months count'],

            // Date
            ['key' => 'today_formatted', 'type' => 'string', 'label' => 'Generation date'],
        ];

        // Bulletin group variables (7 groups × (label + totals + 3 lines × 6 fields))
        $groups = ['sante', 'at_mp', 'retraite', 'famille', 'chomage', 'csg_crds', 'autres_employeur'];

        foreach ($groups as $g) {
            $schema[] = ['key' => "bulletin_group.{$g}.label", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . ' — group label'];
            $schema[] = ['key' => "bulletin_group.{$g}.employee_total_formatted", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . ' — employee total'];
            $schema[] = ['key' => "bulletin_group.{$g}.employer_total_formatted", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . ' — employer total'];

            for ($n = 1; $n <= 3; $n++) {
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_label", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} label"];
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_base_formatted", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} base"];
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_employee_rate", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} employee rate"];
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_employee_formatted", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} employee amount"];
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_employer_rate", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} employer rate"];
                $schema[] = ['key' => "bulletin_group.{$g}.line_{$n}_employer_formatted", 'type' => 'string', 'label' => ucfirst(str_replace('_', ' ', $g)) . " — line {$n} employer amount"];
            }
        }

        return $schema;
    }
}
