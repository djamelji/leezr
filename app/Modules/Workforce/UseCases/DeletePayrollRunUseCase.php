<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\PayrollRun;

class DeletePayrollRunUseCase
{
    public function execute(PayrollRun $run, int $actorId): void
    {
        // Guard: only draft can be deleted
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw new \DomainException("Cannot delete PayrollRun: status is '{$run->status}', expected 'draft'.");
        }

        $runId = $run->id;
        $periodStart = $run->period_start->format('Y-m-d');
        $periodEnd = $run->period_end->format('Y-m-d');

        // Lines cascade via FK, but since status is draft the boot won't block
        $run->delete();

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payroll_run.deleted',
            targetType: 'payroll_run',
            targetId: (string) $runId,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'description' => "PayrollRun deleted for period {$periodStart} to {$periodEnd}",
                ],
            ],
        );
    }
}
