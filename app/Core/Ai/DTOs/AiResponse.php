<?php

namespace App\Core\Ai\DTOs;

final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $structuredData = null,
        public readonly float $confidence = 0.0,
        public readonly int $tokensUsed = 0,
        public readonly int $latencyMs = 0,
        public readonly string $model = '',
        public readonly string $provider = '',
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'structured_data' => $this->structuredData,
            'confidence' => $this->confidence,
            'tokens_used' => $this->tokensUsed,
            'latency_ms' => $this->latencyMs,
            'model' => $this->model,
            'provider' => $this->provider,
        ], fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 0.0);
    }

    public static function empty(string $provider = 'null'): self
    {
        return new self(text: '', provider: $provider);
    }
}
