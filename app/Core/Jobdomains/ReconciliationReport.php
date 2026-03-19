<?php

namespace App\Core\Jobdomains;

/**
 * ADR-375: Value object holding the result of a preset reconciliation.
 *
 * Categorizes each company role into one of:
 * - upToDate: permissions match current preset exactly
 * - drifted: permissions diverge from current preset (fixable)
 * - skipped: role is custom (not is_system) — never touched
 *
 * Also carries warnings (e.g. unknown bundle keys, missing permissions).
 */
class ReconciliationReport
{
    /** @var array<int, array{company_id: int, role_key: string, role_id: int}> */
    public array $upToDate = [];

    /** @var array<int, array{company_id: int, role_key: string, role_id: int, missing: string[], extra: string[], applied: bool}> */
    public array $drifted = [];

    /** @var array<int, array{company_id: int, role_key: string, role_id: int, reason: string}> */
    public array $skipped = [];

    /** @var string[] */
    public array $warnings = [];

    public function addUpToDate(int $companyId, string $roleKey, int $roleId): void
    {
        $this->upToDate[] = [
            'company_id' => $companyId,
            'role_key' => $roleKey,
            'role_id' => $roleId,
        ];
    }

    public function addDrifted(int $companyId, string $roleKey, int $roleId, array $missing, array $extra, bool $applied = false): void
    {
        $this->drifted[] = [
            'company_id' => $companyId,
            'role_key' => $roleKey,
            'role_id' => $roleId,
            'missing' => $missing,
            'extra' => $extra,
            'applied' => $applied,
        ];
    }

    public function addSkipped(int $companyId, string $roleKey, int $roleId, string $reason): void
    {
        $this->skipped[] = [
            'company_id' => $companyId,
            'role_key' => $roleKey,
            'role_id' => $roleId,
            'reason' => $reason,
        ];
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function hasDrift(): bool
    {
        return count($this->drifted) > 0;
    }

    public function summary(): array
    {
        return [
            'up_to_date' => count($this->upToDate),
            'drifted' => count($this->drifted),
            'skipped' => count($this->skipped),
            'warnings' => count($this->warnings),
        ];
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->summary(),
            'up_to_date' => $this->upToDate,
            'drifted' => $this->drifted,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }
}
