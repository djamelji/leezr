<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\Services\DocumentTemplateResolver;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollRun;
use Illuminate\Support\Facades\DB;

class GenerateOfficialPayslipsUseCase
{
    const TEMPLATE_CODE = 'payslip_official_fr';

    public function __construct(
        private GenerateDocumentUseCase $generateDocument,
    ) {}

    /**
     * Generate official payslip PDFs for all lines in a validated PayrollRun.
     * Stricter guards than draft: siret/naf_code mandatory, vault forced, non-deletable.
     *
     * @return \App\Core\Documents\GeneratedDocument[]
     */
    public function execute(PayrollRun $run, int $actorId): array
    {
        // Guard 1: run must be validated
        if ($run->status !== PayrollRun::STATUS_VALIDATED) {
            throw new \DomainException(
                "Cannot generate official payslips: PayrollRun status is '{$run->status}', expected 'validated'."
            );
        }

        // Guard: modules enabled
        if (! ModuleGate::isEnabledGlobally('workforce_payroll')) {
            throw new \DomainException('Module workforce_payroll is not enabled.');
        }
        if (! ModuleGate::isEnabledGlobally('workforce_documents')) {
            throw new \DomainException('Module workforce_documents is not enabled.');
        }

        // Guard 4: Company must have siret + naf_code
        $company = Company::findOrFail($run->company_id);
        if (empty($company->siret)) {
            throw new \DomainException('Cannot generate official payslips: Company SIRET is missing.');
        }
        if (empty($company->naf_code)) {
            throw new \DomainException('Cannot generate official payslips: Company NAF code is missing.');
        }

        // Guard 2+3: all calculations validated, no blocking anomalies
        $lines = $run->lines()->with('calculation')->get();

        $linesWithoutCalc = $lines->filter(fn ($l) => $l->calculation === null)->count();
        if ($linesWithoutCalc > 0) {
            throw new \DomainException(
                "Cannot generate official payslips: {$linesWithoutCalc} line(s) have no calculation."
            );
        }

        $nonValidatedCalcs = $lines->filter(
            fn ($l) => $l->calculation->status !== PayrollCalculation::STATUS_VALIDATED
        )->count();
        if ($nonValidatedCalcs > 0) {
            throw new \DomainException(
                "Cannot generate official payslips: {$nonValidatedCalcs} calculation(s) are not validated."
            );
        }

        $blockingCount = $lines->filter(
            fn ($l) => $l->calculation->hasBlockingAnomalies()
        )->count();
        if ($blockingCount > 0) {
            throw new \DomainException(
                "Cannot generate official payslips: {$blockingCount} calculation(s) have blocking anomalies."
            );
        }

        // Guard 5: template must exist and be active
        DocumentTemplateResolver::resolve($run->company_id, self::TEMPLATE_CODE);

        // Atomic: all documents + audit in a single transaction
        return DB::transaction(function () use ($run, $lines, $actorId) {
            // Generate official payslip for each line
            // forceVault: true → MemberDocument vault mandatory (Guard 6)
            // extraMetadata: non_deletable → document cannot be deleted (Guard 7)
            $documents = [];
            foreach ($lines as $line) {
                $documents[] = $this->generateDocument->execute(
                    companyId: $run->company_id,
                    templateCode: self::TEMPLATE_CODE,
                    subjectType: 'payroll_line',
                    subjectId: $line->id,
                    actorId: $actorId,
                    forceVault: true,
                    extraMetadata: ['official' => true, 'non_deletable' => true],
                );
            }

            // Guard 8: AuditLogger with category workforce.payslip_official
            app(AuditLogger::class)->logCompany(
                companyId: $run->company_id,
                action: 'payslip_official.generated',
                targetType: 'payroll_run',
                targetId: (string) $run->id,
                options: [
                    'actorId' => $actorId,
                    'metadata' => [
                        'category' => 'workforce.payslip_official',
                        'document_count' => count($documents),
                        'employee_count' => $run->employee_count,
                        'template_code' => self::TEMPLATE_CODE,
                    ],
                ],
            );

            return $documents;
        });
    }
}
