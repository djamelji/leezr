<?php

namespace App\Core\Modules\Setup;

use App\Core\Models\Company;

/**
 * ADR-803 / 804 — a module's REAL prerequisites, verified server-side from the module's own source of truth.
 *
 * - checks() : list of {key, ok, required}. « ready » needs every REQUIRED check ; non-required checks are
 *   RECOMMENDATIONS (shown, never blocking).
 * - settingsFingerprint() : hash of the EFFECTIVE mandatory settings (null = the module has none). Stored at
 *   confirmation ; a different value later means « needs review ». Writes without effective change are ignored.
 */
interface ModuleReadiness
{
    /** @return list<array{key:string, ok:bool, required?:bool}> */
    public function checks(Company $company, CompanyModuleSetup $setup): array;

    public function settingsFingerprint(Company $company): ?string;
}
