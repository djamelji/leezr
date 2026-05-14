<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Support\Facades\DB;

class CreateContractUseCase
{
    public function execute(
        Employee $employee,
        string $contractType,
        string $workModelKey,
        string $startDate,
        float $weeklyHours = 35.00,
        ?string $endDate = null,
        ?string $probationEndDate = null,
        ?int $baseSalaryCents = null,
        string $currency = 'EUR',
        string $payFrequency = 'monthly',
        int $overtimeRateBps = 2500,
        ?array $customComplianceRules = null,
        ?int $conventionCollectiveId = null,
    ): EmploymentContract {
        return DB::transaction(function () use (
            $employee, $contractType, $workModelKey, $startDate,
            $weeklyHours, $endDate, $probationEndDate,
            $baseSalaryCents, $currency, $payFrequency,
            $overtimeRateBps, $customComplianceRules, $conventionCollectiveId,
        ) {
            // Deactivate previous current contract
            EmploymentContract::where('employee_id', $employee->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $contract = EmploymentContract::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'contract_type' => $contractType,
                'work_model_key' => $workModelKey,
                'weekly_hours' => $weeklyHours,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'probation_end_date' => $probationEndDate,
                'status' => EmploymentContract::STATUS_DRAFT,
                'is_current' => true,
                'custom_compliance_rules' => $customComplianceRules,
                'convention_collective_id' => $conventionCollectiveId,
            ]);

            // Create initial compensation plan if salary provided
            if ($baseSalaryCents !== null) {
                CompensationPlan::create([
                    'company_id' => $employee->company_id,
                    'contract_id' => $contract->id,
                    'base_salary_cents' => $baseSalaryCents,
                    'currency' => $currency,
                    'pay_frequency' => $payFrequency,
                    'overtime_rate_bps' => $overtimeRateBps,
                    'effective_from' => $startDate,
                ]);
            }

            app(AuditLogger::class)->logCompany(
                companyId: $employee->company_id,
                action: 'contract.created',
                targetType: 'employment_contract',
                targetId: (string) $contract->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.contract',
                        'description' => "Contract {$contractType} created for {$employee->full_name}",
                    ],
                ],
            );

            return $contract;
        });
    }
}
