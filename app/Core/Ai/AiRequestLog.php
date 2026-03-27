<?php

namespace App\Core\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * Centralized AI request logging for observability.
 * Tracks every AI call: provider, model, latency, tokens, errors.
 */
class AiRequestLog extends Model
{
    protected $fillable = [
        'provider', 'model', 'capability',
        'input_tokens', 'output_tokens', 'latency_ms',
        'status', 'error_message',
        'company_id', 'module_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Log an AI request result.
     */
    public static function record(
        string $provider,
        string $model,
        string $capability,
        int $latencyMs,
        string $status = 'success',
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $errorMessage = null,
        ?int $companyId = null,
        ?string $moduleKey = null,
        ?array $metadata = null,
    ): self {
        return self::create([
            'provider' => $provider,
            'model' => $model,
            'capability' => $capability,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_message' => $errorMessage,
            'company_id' => $companyId,
            'module_key' => $moduleKey,
            'metadata' => $metadata,
        ]);
    }
}
