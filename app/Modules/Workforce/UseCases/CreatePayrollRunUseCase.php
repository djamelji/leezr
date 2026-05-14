<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\PayrollRun;

class CreatePayrollRunUseCase
{
    public function execute(int $companyId, string $periodStart, string $periodEnd, int $actorId): PayrollRun
    {
        // Guard: module enabled
        if (! ModuleGate::isEnabledGlobally('workforce_payroll')) {
            throw new \DomainException('Module workforce_payroll is not enabled for this company.');
        }

        // Guard: period_start < period_end
        $start = \Carbon\Carbon::parse($periodStart);
        $end = \Carbon\Carbon::parse($periodEnd);

        if ($start->gte($end)) {
            throw new \DomainException('period_start must be before period_end.');
        }

        // Guard: idempotency — no existing PayrollRun for this period
        $idempotencyKey = "payroll:{$companyId}:{$start->format('Y-m-d')}:{$end->format('Y-m-d')}";

        $existing = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            throw new \DomainException("A PayrollRun already exists for this period (ID: {$existing->id}, status: {$existing->status}).");
        }

        // Resolve company currency
        $company = Company::findOrFail($companyId);

        $run = PayrollRun::create([
            'company_id' => $companyId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => $company->currency ?? 'EUR',
            'idempotency_key' => $idempotencyKey,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'payroll_run.created',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'description' => "PayrollRun created for {$start->format('Y-m-d')} to {$end->format('Y-m-d')}",
                ],
            ],
        );

        return $run;
    }
}
