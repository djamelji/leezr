<?php

namespace App\Core\Modules\Setup;

use App\Core\Models\Company;

/**
 * ADR-803 — a module's REAL prerequisites to be « ready ». Each check is verified server-side from the
 * module's own source of truth ; « ready » is granted only when every check passes.
 */
interface ModuleReadiness
{
    /** @return list<array{key:string, ok:bool}> */
    public function checks(Company $company, CompanyModuleSetup $setup): array;
}
