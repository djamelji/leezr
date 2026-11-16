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

    /**
     * ADR-803.1 — why a past confirmation is no longer trustworthy (e.g. a mandatory setting changed after
     * `configured_at`). Empty = still valid. Computed at read time, never by a background sync.
     *
     * @return list<string>
     */
    public function staleReasons(Company $company, CompanyModuleSetup $setup): array;
}
