<?php

namespace App\Core\Workforce\Dsn;

/**
 * Structured validation result for DSN payload.
 *
 * Each entry has:
 * - severity: 'error' (blocking) or 'warning' (non-blocking)
 * - category: siret, nir, ctp, totals, encoding
 * - rubrique: DSN rubrique concerned (e.g. 'S21.G00.30.001')
 * - field: human-readable field name
 * - message: description
 * - employee_id: nullable, for employee-scoped issues
 * - payroll_line_id: nullable, for line-scoped issues
 *
 * valid = true only when ZERO errors (warnings don't block).
 *
 * Sprint 6.6 — ADR-524: enriched with severity, rubrique, employee_id, payroll_line_id
 */
readonly class DsnValidationResult
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /**
     * @param  bool  $valid     True if payload has no blocking errors
     * @param  array  $entries  Array of validation entries [{severity, category, rubrique, field, message, employee_id?, payroll_line_id?}]
     */
    public function __construct(
        public bool $valid,
        public array $entries,
    ) {}

    /**
     * Create a valid result (no entries).
     */
    public static function ok(): self
    {
        return new self(valid: true, entries: []);
    }

    /**
     * Build result from mixed entries. valid = no errors (warnings OK).
     */
    public static function fromEntries(array $entries): self
    {
        $hasErrors = false;
        foreach ($entries as $entry) {
            if (($entry['severity'] ?? self::SEVERITY_ERROR) === self::SEVERITY_ERROR) {
                $hasErrors = true;
                break;
            }
        }

        return new self(valid: ! $hasErrors, entries: $entries);
    }

    /**
     * Backward compat: get blocking errors only.
     * @deprecated Use entries() with severity filter instead.
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->entries,
            fn ($e) => ($e['severity'] ?? self::SEVERITY_ERROR) === self::SEVERITY_ERROR,
        ));
    }

    /**
     * Get warnings only.
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->entries,
            fn ($e) => ($e['severity'] ?? self::SEVERITY_ERROR) === self::SEVERITY_WARNING,
        ));
    }

    /**
     * Get entries filtered by category.
     */
    public function errorsByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->entries,
            fn ($e) => $e['category'] === $category,
        ));
    }

    /**
     * Get entries for a specific employee.
     */
    public function entriesForEmployee(int $employeeId): array
    {
        return array_values(array_filter(
            $this->entries,
            fn ($e) => ($e['employee_id'] ?? null) === $employeeId,
        ));
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'error_count' => count($this->errors()),
            'warning_count' => count($this->warnings()),
            'entries' => $this->entries,
        ];
    }
}
