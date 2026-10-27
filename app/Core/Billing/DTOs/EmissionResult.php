<?php

namespace App\Core\Billing\DTOs;

/**
 * ADR-632 (M4) — résultat d'une tentative d'émission consolidée.
 * status : disabled (flag OFF) | blocked (garde non satisfaite) | emitted | replayed (idempotent).
 */
readonly class EmissionResult
{
    public function __construct(
        public string $status,
        public ?int $invoiceId = null,
        public ?string $runIdentity = null,
        public array $blockedReasons = [],
        public ?int $linesCount = null,
        public ?int $subtotalHtCents = null,
        public ?int $taxAmountCents = null,
        public ?int $totalTtcCents = null,
        public int $eventsInvoiced = 0,
    ) {}

    public static function disabled(): self
    {
        return new self('disabled', blockedReasons: ['emission_disabled']);
    }

    public static function blocked(array $reasons, ?string $identity = null): self
    {
        return new self('blocked', runIdentity: $identity, blockedReasons: $reasons);
    }

    public static function replayed(int $invoiceId, string $identity): self
    {
        return new self('replayed', invoiceId: $invoiceId, runIdentity: $identity);
    }

    public function wasEmitted(): bool
    {
        return $this->status === 'emitted';
    }
}
