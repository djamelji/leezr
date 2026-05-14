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
            $accrued = false;

            foreach ($leaveTypes as $leaveType) {
                $idempotencyKey = "accrual:{$employee->id}:{$leaveType->id}:{$periodKey}";

                // Compute monthly amount
                $annual = $leaveType->annual_entitlement_hundredths;
                $monthly = (int) floor($annual / 12);

                // Month 12 adjusts for remainder
                if ($month === 12) {
                    $amount = $annual - (11 * $monthly);
                } else {
                    $amount = $monthly;
                }

                if ($amount <= 0) {
                    continue;
                }

                $correlationId = (string) Str::uuid();

                LeaveLedger::credit(
                    companyId: $companyId,
                    employeeId: $employee->id,
                    leaveTypeId: $leaveType->id,
                    amount: $amount,
                    entryType: LeaveLedgerEntry::TYPE_ACCRUAL,
                    periodKey: $periodKey,
                    correlationId: $correlationId,
                    idempotencyKey: $idempotencyKey,
                    description: "Monthly accrual for {$leaveType->code} — period {$periodKey}",
                    actorType: 'system',
                    auditCategory: 'workforce.leave.accrual',
                );

                $accrued = true;
            }

            if ($accrued) {
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
}
