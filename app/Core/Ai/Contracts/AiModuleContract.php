<?php

namespace App\Core\Ai\Contracts;

use App\Core\Ai\AiPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-436: Contract for modules that integrate with the AI engine.
 *
 * Each module implements this interface to declare its AI capabilities,
 * resolve its company-specific AI policy, and dispatch analysis jobs.
 *
 * The AiModuleContractRegistry collects all implementations.
 * AiPolicyResolver delegates to the registry instead of hardcoding match cases.
 */
interface AiModuleContract
{
    /**
     * The module key (must match ModuleRegistry key).
     */
    public function moduleKey(): string;

    /**
     * Policy fields this module supports (for company settings UI).
     *
     * @return array<string, array{type: string, default: mixed}>
     */
    public function policyFields(): array;

    /**
     * Resolve the AI policy for a specific company.
     * Reads company-level settings with fallback to platform defaults.
     */
    public function resolvePolicy(int $companyId): AiPolicy;

    /**
     * Dispatch the async AI analysis job for the given entity.
     */
    public function dispatchAnalysis(Model $entity): void;
}
