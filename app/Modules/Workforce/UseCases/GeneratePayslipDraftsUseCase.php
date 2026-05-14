<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\Services\DocumentTemplateResolver;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollRun;

class GeneratePayslipDraftsUseCase
{
    const TEMPLATE_CODE = 'payslip_draft_fr';

    public function __construct(
        private GenerateDocumentUseCase $generateDocument,
    ) {}

    /**
     * Generate payslip draft PDFs for all lines in a validated PayrollRun.
     *
     * @return \App\Core\Documents\GeneratedDocument[]
     */
    public function execute(PayrollRun $run, int $actorId): array
    {
        // Guard: run must be validated
        if ($run->status !== PayrollRun::STATUS_VALIDATED) {
            throw new \DomainException(
                "Cannot generate payslip drafts: PayrollRun status is '{$run->status}', expected 'validated'."
            );
        }

        // Guard: modules enabled
        if (! ModuleGate::isEnabledGlobally('workforce_payroll')) {
            throw new \DomainException('Module workforce_payroll is not enabled.');
        }
        if (! ModuleGate::isEnabledGlobally('workforce_documents')) {
            throw new \DomainException('Module workforce_documents is not enabled.');
        }

        // Guard: all calculations must be validated with no blocking anomalies
        $lines = $run->lines()->with('calculation')->get();

        $linesWithoutCalc = $lines->filter(fn ($l) => $l->calculation === null)->count();
        if ($linesWithoutCalc > 0) {
            throw new \DomainException(
                "Cannot generate payslip drafts: {$linesWithoutCalc} line(s) have no calculation."
            );
        }

        $nonValidatedCalcs = $lines->filter(
            fn ($l) => $l->calculation->status !== PayrollCalculation::STATUS_VALIDATED
        )->count();
        if ($nonValidatedCalcs > 0) {
            throw new \DomainException(
                "Cannot generate payslip drafts: {$nonValidatedCalcs} calculation(s) are not validated."
            );
        }

        $blockingCount = $lines->filter(
            fn ($l) => $l->calculation->hasBlockingAnomalies()
        )->count();
        if ($blockingCount > 0) {
            throw new \DomainException(
                "Cannot generate payslip drafts: {$blockingCount} calculation(s) have blocking anomalies."
            );
        }

        // Verify template exists (fail fast before iterating)
        DocumentTemplateResolver::resolve($run->company_id, self::TEMPLATE_CODE);

        // Generate payslip draft for each line (idempotent via GenerateDocumentUseCase)
        $documents = [];
        foreach ($lines as $line) {
            $documents[] = $this->generateDocument->execute(
                companyId: $run->company_id,
                templateCode: self::TEMPLATE_CODE,
                subjectType: 'payroll_line',
                subjectId: $line->id,
                actorId: $actorId,
            );
        }

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payslip_drafts.generated',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'document_count' => count($documents),
                    'employee_count' => $run->employee_count,
                    'template_code' => self::TEMPLATE_CODE,
                ],
            ],
        );

        return $documents;
    }
}
