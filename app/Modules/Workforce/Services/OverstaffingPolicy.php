<?php

namespace App\Modules\Workforce\Services;

use App\Core\Policies\CompanyPolicy;

/**
 * Politique de SUREFFECTIF (Smart Planning Lot 4B.1, ADR-719).
 *
 * Alerte OPÉRATIONNELLE (distincte des violations réglementaires de temps de travail, qui restent
 * bloquantes et JAMAIS contournables). Modèle configurable, défaut `warn_confirm` :
 *  - warn               : avertit, n'exige pas de confirmation (l'écriture passe).
 *  - warn_confirm       : avertit et EXIGE une confirmation serveur avant d'enregistrer.
 *  - warn_confirm_reason: idem + motif obligatoire.
 * Stocké dans company_policies (domain=planning, policy_key=overstaffing_mode, value={mode}).
 */
class OverstaffingPolicy
{
    const MODE_WARN = 'warn';
    const MODE_WARN_CONFIRM = 'warn_confirm';
    const MODE_WARN_CONFIRM_REASON = 'warn_confirm_reason';
    const DEFAULT_MODE = self::MODE_WARN_CONFIRM;
    const MODES = [self::MODE_WARN, self::MODE_WARN_CONFIRM, self::MODE_WARN_CONFIRM_REASON];

    public function mode(int $companyId): string
    {
        $policy = CompanyPolicy::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->domain('planning')->forKey('overstaffing_mode')
            ->activeAt()
            ->orderByDesc('effective_from')
            ->first();

        $mode = $policy->value['mode'] ?? null;

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    public function requiresConfirmation(string $mode): bool
    {
        return $mode === self::MODE_WARN_CONFIRM || $mode === self::MODE_WARN_CONFIRM_REASON;
    }

    public function requiresReason(string $mode): bool
    {
        return $mode === self::MODE_WARN_CONFIRM_REASON;
    }
}
