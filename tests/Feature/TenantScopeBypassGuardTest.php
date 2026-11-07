<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ADR-733.1/733.2 (P0-D.1/.2) — Garde-fou CI des contournements de scope tenant.
 *
 * `withoutGlobalScopes()->{find|findOrFail|first|firstOrFail|whereKey}($pk)` charge une ligne TENANT par
 * PK EN IGNORANT l'isolation (et le mode strict). Légitime dans certains flux (PK dérivée d'un parent
 * déjà tenant-résolu, flux signature par token, racine `Company::`), mais chaque NOUVEL usage doit être
 * revu. Ce test fige la baseline (allowlist) et ÉCHOUE sur tout usage hors-liste → revue d'isolation CI.
 * Le pattern HTTP sûr `->where('company_id',…)->findOrFail` ne matche pas (le `where` est avant le find).
 *
 * LIMITE connue (consignée ADR-733.2 §d) : `DB::table('<table_tenant>')` contourne AUSSI `CompanyScope`,
 * le mode strict ET ce garde (Query Builder brut). Les occurrences actuelles sont filtrées manuellement
 * par `company_id` ; une règle CI dédiée `DB::table` reste à ajouter (lot ultérieur, pas d'analyseur géant).
 */
class TenantScopeBypassGuardTest extends TestCase
{
    /** Pattern dangereux : scope retiré puis chargement par PK (sans re-filtre company_id). */
    private const PATTERN = '/(withoutGlobalScopes|withoutCompanyScope)\(\)->(find|findOrFail|first|firstOrFail|whereKey)\(/';

    /** Racine bénigne : charger la Company elle-même (pas un enfant d'un autre tenant). */
    private const ROOT_EXEMPT = '/Company::withoutGlobalScopes\(\)->(find|first)/';

    /** Baseline au 2026-11-07 (ADR-733.2). Tous classés A=0 / B / C (cf. ADR). Réduire = durcissement. */
    private const ALLOWLIST = [
        // Jobs — contexte établi (runFor / binding) — classe B
        'app/Core/Automation/Jobs/ProcessWorkflowJob.php',
        'app/Jobs/Billing/ProcessDunningBatchJob.php',
        'app/Jobs/Documents/ProcessDocumentAiJob.php',
        // Paie / DSN — PK dérivée d'un parent tenant-résolu (B) ; CLI opérateur (C)
        'app/Core/Workforce/PayrollCalculationEngine.php',
        'app/Console/Commands/DsnRegenerateCommand.php',
        'app/Modules/Workforce/ReadModels/DsnDeclarationReadModel.php',
        'app/Modules/Workforce/UseCases/ComputePayrollCalculationsUseCase.php',
        'app/Modules/Workforce/UseCases/ExportPayrollDsnUseCase.php',
        'app/Modules/Workforce/UseCases/AccrueLeaveUseCase.php',
        'app/Modules/Workforce/UseCases/BulkCreateShiftsUseCase.php',
        'app/Modules/Workforce/UseCases/UpdateShiftUseCase.php',
        'app/Modules/Workforce/Services/WorkTimeComplianceChecker.php',
        // Timesheet — PK dérivée de la période/run tenant-résolu (whereKey) — B
        'app/Modules/Workforce/UseCases/SubmitTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/LockTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/ApproveTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/RejectTimesheetUseCase.php',
        'app/Modules/Workforce/UseCases/ReopenTimesheetUseCase.php',
        // Signature — racine de confiance = TOKEN opaque haché (ou parent HTTP tenant) — B
        'app/Modules/Workforce/Signature/Http/SignatureController.php',
        'app/Modules/Workforce/Signature/Http/SignerController.php',
        'app/Modules/Workforce/Signature/Services/ExternalPadesCoordinator.php',
        'app/Modules/Workforce/Signature/Services/SignerEnrollmentService.php',
        'app/Modules/Workforce/Signature/Services/SignerIdentityResolver.php',
        'app/Modules/Workforce/Signature/UseCases/RecordSignerActionUseCase.php',
        'app/Modules/Workforce/Signature/UseCases/SendSignatureRequestUseCase.php',
    ];

    public function test_no_new_uncontrolled_scope_bypass(): void
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
                if (! preg_match(self::PATTERN, $line) || preg_match(self::ROOT_EXEMPT, $line)) {
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
            "Nouveau contournement de scope tenant (withoutGlobalScopes()->find/first/whereKey) hors allowlist — revue d'isolation requise (ADR-733.2 §c) :\n".implode("\n", $offenders)
        );
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = [];
        foreach (self::ALLOWLIST as $rel) {
            $path = base_path($rel);
            if (! file_exists($path)) {
                $stale[] = "$rel (absent)";

                continue;
            }
            if (! preg_match(self::PATTERN, file_get_contents($path))) {
                $stale[] = "$rel (pattern retiré → à sortir de l'allowlist)";
            }
        }
        $this->assertEmpty($stale, "Entrées d'allowlist périmées (retirer = durcissement) :\n".implode("\n", $stale));
    }
}
