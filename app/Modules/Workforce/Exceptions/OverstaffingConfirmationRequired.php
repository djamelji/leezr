<?php

namespace App\Modules\Workforce\Exceptions;

/**
 * Levée par les UseCases d'écriture quand une affectation crée un SUREFFECTIF marginal et que la
 * politique exige une confirmation absente/périmée (ADR-719). Porte l'impact + la signature serveur
 * (HMAC) à ré-échoer pour confirmer. N'est PAS une violation réglementaire (celles-ci restent bloquantes).
 */
class OverstaffingConfirmationRequired extends \DomainException
{
    /**
     * @param  array<int, array<string, mixed>>  $impacts
     */
    public function __construct(
        public array $impacts,
        public string $signature,
        public string $mode,
        public bool $requiresReason,
    ) {
        parent::__construct('Overstaffing requires confirmation.');
    }

    public function toPayload(): array
    {
        return [
            'message' => 'Cette affectation dépasse le besoin de couverture.',
            'code' => 'overstaffing_requires_confirmation',
            'mode' => $this->mode,
            'requires_reason' => $this->requiresReason,
            'signature' => $this->signature,
            'impacts' => $this->impacts,
        ];
    }
}
