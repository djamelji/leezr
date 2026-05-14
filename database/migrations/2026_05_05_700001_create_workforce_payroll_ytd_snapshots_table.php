<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_ytd_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->smallInteger('fiscal_year');
            $table->tinyInteger('period_month'); // 1-12
            $table->foreignId('payroll_calculation_id')->constrained('workforce_payroll_calculations')->cascadeOnDelete();

            // Cumuls bruts (mois 1 → M inclus)
            $table->bigInteger('ytd_gross_total_cents');
            $table->bigInteger('ytd_gross_basis_cents');

            // Cumuls cotisations
            $table->bigInteger('ytd_contributions_employee_cents');
            $table->bigInteger('ytd_contributions_employer_cents');
            $table->bigInteger('ytd_total_cost_employer_cents');

            // Cumuls plafonnement
            $table->bigInteger('ytd_plafond_ss_cents'); // plafond_mensuel × M
            $table->bigInteger('ytd_base_plafonnee_cents'); // min(ytd_gross, ytd_plafond)
            $table->bigInteger('ytd_base_tranche2_cents'); // max(0, min(ytd_gross, ytd_plafond×8) - ytd_plafond)

            // Cumuls fiscaux
            $table->bigInteger('ytd_taxable_income_cents');
            $table->bigInteger('ytd_tax_cents');
            $table->bigInteger('ytd_net_before_tax_cents');
            $table->bigInteger('ytd_net_payable_cents');

            // Cumuls divers
            $table->bigInteger('ytd_benefits_cents')->default(0);
            $table->bigInteger('ytd_deductions_cents')->default(0);

            // Détail cotisations cumulées
            $table->json('ytd_contribution_lines');

            // Versioning & traçabilité
            $table->string('snapshot_version', 30); // 'ytd-snapshot-v1'
            $table->string('calc_rule_version', 50); // from PayrollCalculation.rule_version
            $table->string('resolver_version', 50)->nullable(); // from calculation_snapshot.resolver_version
            $table->json('months_included'); // [1,2,3...M]
            $table->json('rule_versions_used'); // {1: 'v2', 2: 'v2', ...}

            $table->timestamps();

            // Unique constraints (garde-fous user)
            $table->unique(['company_id', 'employee_id', 'fiscal_year', 'period_month'], 'ytd_employee_month_unique');
            $table->unique('payroll_calculation_id', 'ytd_calculation_unique');

            // Query indexes
            $table->index('company_id');
            $table->index(['employee_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_ytd_snapshots');
    }
};
