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
        string $compensationType = 'monthly',
        ?int $hourlyRateCents = null,
        ?int $dailyRateCents = null,
    ): EmploymentContract {
        return DB::transaction(function () use (
            $employee, $contractType, $workModelKey, $startDate,
            $weeklyHours, $endDate, $probationEndDate,
            $baseSalaryCents, $currency, $payFrequency,
            $overtimeRateBps, $customComplianceRules, $conventionCollectiveId,
            $compensationType, $hourlyRateCents, $dailyRateCents,
        ) {
            // LOT G (ANO-016): forbid genuine period overlap with a non-terminated contract of the
            // same employee. Contiguous succession (e.g. CDD then CDI on non-overlapping dates) stays
            // allowed; two concurrent contracts covering the same date do not. Amendment (avenant)
            // versioning remains a deferred product arbitration (ADR-574) — overlapping on purpose is
            // intentionally refused here until that flow exists.
            $overlap = EmploymentContract::where('employee_id', $employee->id)
                ->where('status', '!=', EmploymentContract::STATUS_TERMINATED)
                ->where(function ($q) use ($startDate, $endDate) {
                    // existing.start_date <= newEnd  (newEnd null = open-ended → no upper bound)
                    if ($endDate !== null) {
                        $q->whereDate('start_date', '<=', $endDate);
                    }
                    // AND (existing.end_date is null OR existing.end_date >= newStart)
                    $q->where(function ($q2) use ($startDate) {
                        $q2->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate);
                    });
                })
                ->exists();

            if ($overlap) {
                throw new \DomainException('Contract period overlaps an existing active contract for this employee; succession must be non-overlapping.');
            }

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

            // Create initial compensation plan if any salary info provided
            $hasSalaryInfo = $baseSalaryCents !== null || $hourlyRateCents !== null || $dailyRateCents !== null;
            if ($hasSalaryInfo) {
                CompensationPlan::create([
                    'company_id' => $employee->company_id,
                    'contract_id' => $contract->id,
                    'compensation_type' => $compensationType,
                    'base_salary_cents' => $baseSalaryCents ?? 0,
                    'hourly_rate_cents' => $hourlyRateCents,
                    'daily_rate_cents' => $dailyRateCents,
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
