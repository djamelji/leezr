<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * @deprecated Use ModuleActivationEngine directly.
 * Thin delegate kept for backward compatibility during transition.
 */
class CompanyModuleService
{
    /**
     * @return array{success: bool, status: int, data: array}
     */
    public static function enable(Company $company, string $key): array
    {
        return ModuleActivationEngine::enable($company, $key);
    }

    /**
     * @return array{success: bool, status: int, data: array}
     */
    public static function disable(Company $company, string $key): array
    {
        return ModuleActivationEngine::disable($company, $key);
    }
}
