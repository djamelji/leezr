<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Str;

class AccrueLeaveUseCase
{
    /**
     * Batch accrual operation for a company.
     *
     * For each active employee with an active contract:
     *   For each enabled leave type with accrual_mode=monthly:
     *     - Compute monthly accrual = floor(annual_entitlement_hundredths / 12)
     *     - Month 12 adjusts remainder = annual - (11 * monthly)
     *     - Idempotency_key: accrual:{employee_id}:{leave_type_id}:{period_key}
     *
     * @return int Number of employees processed
     */
    public function execute(int $companyId, string $periodKey): int
    {
        // Parse period_key to determine month number
        $parts = explode('-', $periodKey);

        if (count($parts) !== 2) {
            throw new \DomainException("Invalid period_key format: '{$periodKey}'. Expected 'YYYY-MM'.");
        }

        $month = (int) $parts[1];

        if ($month < 1 || $month > 12) {
            throw new \DomainException("Invalid month in period_key: {$month}");
        }

        // Get all enabled leave types with monthly accrual for this company
        $leaveTypes = LeaveType::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->enabled()
            ->where('accrual_mode', LeaveType::ACCRUAL_MONTHLY)
            ->get();

        if ($leaveTypes->isEmpty()) {
            return 0;
        }

        // Get all active employees with active current contracts
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->active()
            ->whereHas('currentContract', function ($q) {
                $q->where('status', EmploymentContract::STATUS_ACTIVE);
            })
            ->get();

        $processedCount = 0;

        foreach ($employees as $employee) {
            if ($this->accrueEmployee($companyId, $employee->id, $leaveTypes, $periodKey, $month)) {
                $processedCount++;
            }
        }

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'leave_accrual.batch_completed',
            targetType: 'company',
            targetId: (string) $companyId,
            options: [
                'metadata' => [
                    'category' => 'workforce.leave.accrual',
                    'period_key' => $periodKey,
                    'employees_processed' => $processedCount,
                    'leave_types_count' => $leaveTypes->count(),
                ],
            ],
        );

        return $processedCount;
    }

    /**
     * W-HR-UX-5 (P3) — accrual pour UN salarié (déclenché à l'embauche = activation du 1er contrat),
     * afin qu'il dispose immédiatement de la part du mois courant sans attendre le batch mensuel.
     *
     * Réutilise EXACTEMENT la même idempotency_key que le batch (`accrual:{emp}:{type}:{period}`) :
     * quand le batch mensuel passera, il ne re-créditera pas ce salarié pour ce mois → aucun double.
     * Ne crédite QUE les types avec une règle d'accrual configurée (accrual_mode=monthly, entitlement>0).
     *
     * @return bool true si au moins un crédit a été posé
     */
    public function executeForEmployee(int $companyId, int $employeeId, string $periodKey): bool
    {
        $parts = explode('-', $periodKey);
        if (count($parts) !== 2) {
            throw new \DomainException("Invalid period_key format: '{$periodKey}'. Expected 'YYYY-MM'.");
        }
        $month = (int) $parts[1];
        if ($month < 1 || $month > 12) {
            throw new \DomainException("Invalid month in period_key: {$month}");
        }

        $leaveTypes = LeaveType::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->enabled()
            ->where('accrual_mode', LeaveType::ACCRUAL_MONTHLY)
            ->get();

        if ($leaveTypes->isEmpty()) {
            return false;
        }

        return $this->accrueEmployee($companyId, $employeeId, $leaveTypes, $periodKey, $month);
    }

    /**
     * Crédite la part mensuelle de chaque type d'accrual mensuel pour un salarié donné. Idempotent.
     *
     * @param  \Illuminate\Support\Collection<int,LeaveType>  $leaveTypes
     */
    private function accrueEmployee(int $companyId, int $employeeId, $leaveTypes, string $periodKey, int $month): bool
    {
        $accrued = false;

        foreach ($leaveTypes as $leaveType) {
            $idempotencyKey = "accrual:{$employeeId}:{$leaveType->id}:{$periodKey}";

            $annual = $leaveType->annual_entitlement_hundredths;
            $monthly = (int) floor($annual / 12);
            $amount = $month === 12 ? $annual - (11 * $monthly) : $monthly;

            if ($amount <= 0) {
                continue;
            }

            LeaveLedger::credit(
                companyId: $companyId,
                employeeId: $employeeId,
                leaveTypeId: $leaveType->id,
                amount: $amount,
                entryType: LeaveLedgerEntry::TYPE_ACCRUAL,
                periodKey: $periodKey,
                correlationId: (string) Str::uuid(),
                idempotencyKey: $idempotencyKey,
                description: "Monthly accrual for {$leaveType->code} — period {$periodKey}",
                actorType: 'system',
                auditCategory: 'workforce.leave.accrual',
            );

            $accrued = true;
        }

        return $accrued;
    }
}
