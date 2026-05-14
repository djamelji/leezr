<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\BenefitResult;
use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\DTOs\DeductionResult;
use App\Core\Workforce\DTOs\NetResult;
use App\Core\Workforce\DTOs\TaxResult;

class NetAggregator
{
    /**
     * Aggregate gross → net payable.
     *
     * Formula (no double-counting):
     *   net_before_tax = gross_total - contributions_employee - deductions
     *   net_payable = net_before_tax - tax
     *   total_cost_employer = gross_total + contributions_employer
     *
     * Benefits are ALREADY included in gross_total — never re-added here.
     * Pure — no DB access.
     */
    public static function aggregate(
        int $grossTotalCents,
        ContributionResult $contributions,
        TaxResult $tax,
        BenefitResult $benefits,
        DeductionResult $deductions
    ): NetResult {
        $netBeforeTax = $grossTotalCents - $contributions->totalEmployeeCents - $deductions->totalCents;
        $netPayable = $netBeforeTax - $tax->taxCents;
        $totalCostEmployer = $grossTotalCents + $contributions->totalEmployerCents;

        return new NetResult(
            netBeforeTaxCents: $netBeforeTax,
            netPayableCents: $netPayable,
            totalCostEmployerCents: $totalCostEmployer,
        );
    }
}
