<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ADR-733.1 (P0-D.1) — Garde-fou CI des contournements de scope tenant.
 *
 * `withoutGlobalScopes()->find($pk)` / `withoutCompanyScope()->find($pk)` chargent une ligne TENANT par
 * PK EN IGNORANT l'isolation (et le mode strict). C'est légitime dans certains flux (PK dérivée d'un
 * parent déjà tenant-résolu, flux signature par token, root `Company::`), mais chaque NOUVEL usage doit
 * être revu. Ce test fige la baseline connue (allowlist, ADR-733.1 §c) et ÉCHOUE sur tout nouvel usage
 * hors-liste → force une revue d'isolation en CI. (Le pattern HTTP sûr `->where('company_id',…)->findOrFail`
 * ne matche pas : le `where` est entre le scope et le find.)
 */
class TenantScopeBypassGuardTest extends TestCase
{
    /** Baseline connue au 2026-11-07 (ADR-733.1). Réduire cette liste = durcissement ; l'augmenter exige une revue. */
    private const ALLOWLIST = [
        // Jobs (contexte établi par runFor / binding — cf. ADR-733.1)
        'app/Core/Automation/Jobs/ProcessWorkflowJob.php',
        'app/Jobs/Billing/ProcessDunningBatchJob.php',
        'app/Jobs/Documents/ProcessDocumentAiJob.php',
        // Paie / DSN : PK dérivée d'un parent déjà tenant-résolu (dette MOYEN, durcissement préventif à venir)
        'app/Core/Workforce/PayrollCalculationEngine.php',
        'app/Console/Commands/DsnRegenerateCommand.php',
        'app/Modules/Workforce/ReadModels/DsnDeclarationReadModel.php',
        'app/Modules/Workforce/UseCases/ComputePayrollCalculationsUseCase.php',
        'app/Modules/Workforce/UseCases/ExportPayrollDsnUseCase.php',
        'app/Modules/Workforce/UseCases/AccrueLeaveUseCase.php',
        'app/Modules/Workforce/UseCases/BulkCreateShiftsUseCase.php',
        'app/Modules/Workforce/UseCases/GenerateTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/LockTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/SubmitTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/UpdateShiftUseCase.php',
        'app/Modules/Workforce/Services/ShiftRuleValidator.php',
        'app/Modules/Workforce/Services/WorkTimeComplianceChecker.php',
        'app/Modules/Workforce/Employees/Http/EmployeeSelfController.php',
        // Signature : flux par token (racine de confiance), cross-tenant assumé et documenté
        'app/Modules/Workforce/Signature/Http/SignatureController.php',
        'app/Modules/Workforce/Signature/Http/SignerController.php',
        'app/Modules/Workforce/Signature/Services/ExternalPadesCoordinator.php',
        'app/Modules/Workforce/Signature/Services/SignatureSettingsService.php',
        'app/Modules/Workforce/Signature/Services/SignerEnrollmentService.php',
        'app/Modules/Workforce/Signature/Services/SignerIdentityResolver.php',
        'app/Modules/Workforce/Signature/UseCases/FinalizeSignatureRequestUseCase.php',
        'app/Modules/Workforce/Signature/UseCases/RecordSignerActionUseCase.php',
        'app/Modules/Workforce/Signature/UseCases/RemindSignatureRequestUseCase.php',
        'app/Modules/Workforce/Signature/UseCases/SendSignatureRequestUseCase.php',
    ];

    public function test_no_new_uncontrolled_scope_bypass_find(): void
    {
        $base = base_path();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
            foreach (file($file->getPathname()) as $line) {
                if (! preg_match('/(withoutGlobalScopes|withoutCompanyScope)\(\)->find\(/', $line)) {
                    continue;
                }
                // Root tenant load `Company::withoutGlobalScopes()->find(...)` = bénin (charge la racine, pas un enfant).
                if (str_contains($line, 'Company::withoutGlobalScopes()->find')) {
                    continue;
                }
                if (! in_array($rel, self::ALLOWLIST, true)) {
                    $offenders[] = $rel;
                }
                break;
            }
        }

        $offenders = array_values(array_unique($offenders));
        $this->assertEmpty(
            $offenders,
            "Nouveau contournement de scope tenant (withoutGlobalScopes()->find) hors allowlist — revue d'isolation requise (ADR-733.1 §c) :\n".implode("\n", $offenders)
        );
    }

    /** L'allowlist ne doit pas pourrir : toute entrée doit encore contenir le pattern (sinon la retirer = durcissement). */
    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = [];
        foreach (self::ALLOWLIST as $rel) {
            $path = base_path($rel);
            if (! file_exists($path)) {
                $stale[] = "$rel (absent)";

                continue;
            }
            $content = file_get_contents($path);
            if (! preg_match('/(withoutGlobalScopes|withoutCompanyScope)\(\)->find\(/', $content)) {
                $stale[] = "$rel (pattern retiré → à sortir de l'allowlist)";
            }
        }
        $this->assertEmpty($stale, "Entrées d'allowlist périmées (retirer = durcissement) :\n".implode("\n", $stale));
    }
}
