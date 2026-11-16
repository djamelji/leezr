<?php

namespace App\Core\Modules\Setup;

use App\Core\Models\Company;

/**
 * ADR-805 — optional contribution of a module's readiness class : what is STILL RUNNING or still relied upon
 * when the module is deactivated. Read-only : it never blocks nor changes the deactivation (ModuleActivationEngine
 * stays the only authority) ; it makes the consequences explicit before the company confirms.
 *
 * Each item : key (i18n), count, continues (true = the process goes on without the module, false = paused
 * until reactivation). Nothing is ever deleted by a deactivation.
 */
interface ModuleDeactivationImpact
{
    /** @return list<array{key:string, count:int, continues:bool}> */
    public function deactivationImpact(Company $company): array;
}
