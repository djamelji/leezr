<?php

namespace App\Core\Ai\DTOs;

/**
 * ADR-413: Structured, action-oriented insight from AI analysis.
 *
 * Stored in ai_insights JSON column on documents.
 * Frontend renders these as VAlert with severity + translated message.
 */
final class AiInsight
{
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly string $messageKey,
        public readonly array $messageParams = [],
        public readonly ?array $metadata = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'messageKey' => $this->messageKey,
            'messageParams' => $this->messageParams,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            severity: $data['severity'],
            messageKey: $data['messageKey'],
            messageParams: $data['messageParams'] ?? [],
            metadata: $data['metadata'] ?? null,
        );
    }
}
