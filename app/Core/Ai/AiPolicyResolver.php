<?php

namespace App\Core\Ai;

/**
 * ADR-413 + ADR-436: Resolves AI policy for a given module + company.
 *
 * Cascade (settings-driven, NOT plan-driven):
 *   1. Platform gate: no active provider → disabled
 *   2. Registry lookup: AiModuleContractRegistry → module.resolvePolicy()
 *   3. Unknown module → disabled
 *
 * ADR-436: Delegates to AiModuleContractRegistry instead of hardcoded match.
 * Each module implements AiModuleContract and registers itself at boot.
 */
class AiPolicyResolver
{
    public static function forModule(int $companyId, string $moduleKey): AiPolicy
    {
        // Gate 1: No active AI provider on platform → disabled
        if (PlatformAiModule::active()->doesntExist()) {
            return AiPolicy::disabled();
        }

        // Gate 2: Registry-based module resolution (ADR-436)
        $module = AiModuleContractRegistry::get($moduleKey);
        if (! $module) {
            return AiPolicy::disabled();
        }

        return $module->resolvePolicy($companyId);
    }
}
