<?php

namespace App\Modules\Workforce\Security;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use JsonSerializable;

/**
 * PRODUCT-UX-2 / LOT C2 — Server-side salary confidentiality (ADR-680).
 *
 * C1 masked compensation amounts only in Vue; the API still returned every
 * monetary field to any `payroll_prepare` holder. This service enforces the
 * rule SERVER-SIDE: a viewer without `workforce.compensation_read` never
 * receives confidential salary amounts in a payroll JSON response.
 *
 * Non-confidential PREPARATION data (worked time, overtime, leave days,
 * anomalies, statuses, periods, employee identity) is preserved — only the
 * monetary values are stripped. Owner keeps its bypass via WorkforceAccess.
 */
class PayrollConfidentiality
{
    /** Monetary scalar keys → set to null when the viewer may not read compensation. */
    private const AMOUNT_KEYS = [
        // Run / statistics aggregates
        'total_gross_cents', 'total_gross', 'total_net_cents', 'total_employer_cost_cents',
        // Line summary aggregates
        'total_gross_basis_cents', 'avg_gross_basis_cents',
        // Line amounts & rates
        'base_salary_cents', 'gross_basis_cents', 'overtime_rate_bps',
        // Compensation plan amounts & rates (if ever serialized)
        'hourly_rate_cents', 'daily_rate_cents', 'base_salary', 'hourly_rate', 'daily_rate', 'overtime_rate',
        // Calculation amounts
        'gross_total_cents', 'contributions_employee_cents', 'contributions_employer_cents',
        'total_cost_employer_cents', 'taxable_income_cents', 'tax_cents',
        'net_before_tax_cents', 'net_after_tax_cents', 'net_payable_cents', 'net_social_cents',
        'benefits_cents', 'deductions_cents', 'reliefs_cents', 'rgdu_cents',
        // Contribution-line inner amounts (defense in depth)
        'base_cents', 'employee_cents', 'employer_cents', 'amount_cents',
    ];

    /** Structured keys carrying amounts/rates/salary → removed wholesale when unauthorized. */
    private const DETAIL_KEYS = [
        'gross_breakdown', 'compensation_snapshot', 'contribution_lines', 'tax_breakdown',
        'benefit_lines', 'deduction_lines', 'relief_lines',
        // Snapshots & payloads that embed salaries / calculated amounts
        'exported_snapshot', 'policy_snapshot', 'export_payload', 'calculation_snapshot', 'payload_snapshot',
    ];

    /** May the current request read compensation amounts? (owner bypass included). */
    public static function canRead(Request $request): bool
    {
        return WorkforceAccess::canReadCompensation($request);
    }

    /**
     * Return the payload untouched when authorized; otherwise a deep copy with
     * every confidential monetary value stripped and a `compensation_hidden`
     * flag added at the top level (so the client shows "masked", not "zero").
     */
    public static function scrub(mixed $payload, bool $canRead): mixed
    {
        if ($canRead) {
            return $payload;
        }

        $scrubbed = self::walk($payload);

        if (is_array($scrubbed)) {
            $scrubbed['compensation_hidden'] = true;
        }

        return $scrubbed;
    }

    private static function walk(mixed $node): mixed
    {
        // Normalise nested Eloquent models / paginators / JsonSerializable so the
        // scrub reaches confidential keys at ANY depth (not just the top payload).
        if ($node instanceof Arrayable) {
            $node = $node->toArray();
        } elseif ($node instanceof JsonSerializable) {
            $node = $node->jsonSerialize();
        }

        if (! is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            // Remove the key ENTIRELY (not just null) so no confidential field
            // name or amount survives in the JSON — "absence effective de champs".
            if (is_string($key) && self::isConfidential($key)) {
                continue;
            }
            $out[$key] = self::walk($value);
        }

        return $out;
    }

    private static function isConfidential(string $key): bool
    {
        return in_array($key, self::AMOUNT_KEYS, true) || in_array($key, self::DETAIL_KEYS, true);
    }
}
