<?php

namespace App\Modules\Workforce\Signature\Providers\Results;

/**
 * W-SIGNATURE-1 (ADR-651) — résultat du traitement d'un événement webhook prestataire.
 */
class WebhookOutcome
{
    public function __construct(
        public readonly bool $handled,
        public readonly ?int $signatureRequestId = null,
        public readonly ?string $signerReference = null,
        public readonly ?string $action = null, // signed | declined | completed | failed | ignored
        public readonly array $metadata = [],
    ) {}

    public static function ignored(): self
    {
        return new self(handled: false, action: 'ignored');
    }
}
