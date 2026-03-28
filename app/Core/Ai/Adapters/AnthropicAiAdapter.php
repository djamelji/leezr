<?php

namespace App\Core\Ai\Adapters;

use App\Core\Ai\AiRequestLog;
use App\Core\Ai\Contracts\AiProviderAdapter;
use App\Core\Ai\DTOs\AiCapability;
use App\Core\Ai\DTOs\AiHealthResult;
use App\Core\Ai\DTOs\AiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic AI adapter — Claude API via Messages endpoint.
 *
 * Supports: vision (multimodal), text completion, text extraction.
 * Uses Claude's native PDF/image support — no conversion needed.
 */
class AnthropicAiAdapter implements AiProviderAdapter
{
    private const BASE_URL = 'https://api.anthropic.com/v1';

    private const API_VERSION = '2023-06-01';

    public function key(): string
    {
        return 'anthropic';
    }

    public function capabilities(): array
    {
        return [
            AiCapability::Vision,
            AiCapability::Completion,
            AiCapability::TextExtraction,
        ];
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey());
    }

    public function healthCheck(): AiHealthResult
    {
        $start = microtime(true);

        if (! $this->apiKey()) {
            return new AiHealthResult(
                status: 'misconfigured',
                message: 'API key not configured',
            );
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->headers())
                ->post(self::BASE_URL.'/messages', [
                    'model' => $this->model(),
                    'max_tokens' => 10,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Reply with "ok"'],
                    ],
                ]);

            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return new AiHealthResult(
                    status: 'healthy',
                    message: 'Claude API responding ('.$this->model().')',
                    latencyMs: $latencyMs,
                );
            }

            return new AiHealthResult(
                status: 'down',
                message: 'HTTP '.$response->status().': '.($response->json('error.message') ?? $response->body()),
                latencyMs: $latencyMs,
            );
        } catch (ConnectionException $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return new AiHealthResult(
                status: 'down',
                message: 'Connection failed: '.$e->getMessage(),
                latencyMs: $latencyMs,
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return new AiHealthResult(
                status: 'down',
                message: $e->getMessage(),
                latencyMs: $latencyMs,
            );
        }
    }

    public function complete(string $prompt, array $options = []): AiResponse
    {
        return $this->sendMessage(
            messages: [['role' => 'user', 'content' => $prompt]],
            capability: 'completion',
            options: $options,
        );
    }

    public function vision(string $imagePath, string $prompt, array $options = []): AiResponse
    {
        if (! file_exists($imagePath)) {
            Log::warning('AnthropicAiAdapter: image not found', ['path' => $imagePath]);

            return AiResponse::empty('anthropic');
        }

        $mediaType = $this->detectMediaType($imagePath);
        $content = [];

        if ($mediaType === 'application/pdf') {
            // Claude native PDF support via base64 source
            $content[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode(file_get_contents($imagePath)),
                ],
            ];
        } else {
            // Image (PNG, JPG, GIF, WebP)
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mediaType,
                    'data' => base64_encode(file_get_contents($imagePath)),
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $this->sendMessage(
            messages: [['role' => 'user', 'content' => $content]],
            capability: 'vision',
            options: $options,
        );
    }

    public function extractText(string $imagePath, array $options = []): AiResponse
    {
        return $this->vision(
            $imagePath,
            'Extract all visible text from this document. Return the text exactly as written, preserving layout and structure. If there are MRZ lines, include them exactly.',
            $options,
        );
    }

    /**
     * Core Anthropic Messages API call with retry.
     */
    private function sendMessage(array $messages, string $capability, array $options): AiResponse
    {
        $model = $options['model'] ?? $this->model();
        $maxTokens = $options['max_tokens'] ?? 4096;
        $timeout = $options['timeout'] ?? config('ai.anthropic.timeout', 60);

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        $maxAttempts = 3;
        $backoff = [1, 2, 4];
        $lastError = null;
        $start = microtime(true);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($this->headers())
                    ->post(self::BASE_URL.'/messages', $payload);

                $latencyMs = (int) ((microtime(true) - $start) * 1000);

                if ($response->successful()) {
                    $data = $response->json();
                    $text = $this->extractTextFromResponse($data);
                    $structuredData = $this->parseJsonFromText($text);

                    $inputTokens = $data['usage']['input_tokens'] ?? 0;
                    $outputTokens = $data['usage']['output_tokens'] ?? 0;

                    $aiResponse = new AiResponse(
                        text: $text,
                        structuredData: $structuredData,
                        confidence: $this->extractConfidence($structuredData),
                        tokensUsed: $inputTokens + $outputTokens,
                        latencyMs: $latencyMs,
                        model: $data['model'] ?? $model,
                        provider: 'anthropic',
                    );

                    $this->log(
                        model: $data['model'] ?? $model,
                        capability: $capability,
                        latencyMs: $latencyMs,
                        inputTokens: $inputTokens,
                        outputTokens: $outputTokens,
                        companyId: $options['company_id'] ?? null,
                        moduleKey: $options['module_key'] ?? null,
                    );

                    return $aiResponse;
                }

                // Handle rate limiting with retry-after
                if ($response->status() === 429) {
                    $retryAfter = (int) $response->header('retry-after', $backoff[$attempt] ?? 4);
                    sleep(min($retryAfter, 30));

                    continue;
                }

                $lastError = 'HTTP '.$response->status().': '.($response->json('error.message') ?? $response->body());
            } catch (ConnectionException $e) {
                $lastError = 'Connection failed: '.$e->getMessage();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($backoff[$attempt] ?? 4);
            }
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        Log::warning('AnthropicAiAdapter: all attempts failed', [
            'model' => $model,
            'error' => $lastError,
            'attempts' => $maxAttempts,
        ]);

        $this->log(
            model: $model,
            capability: $capability,
            latencyMs: $latencyMs,
            status: 'error',
            errorMessage: $lastError,
            companyId: $options['company_id'] ?? null,
            moduleKey: $options['module_key'] ?? null,
        );

        return AiResponse::empty('anthropic');
    }

    private function extractTextFromResponse(array $data): string
    {
        $parts = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            }
        }

        return implode("\n", $parts);
    }

    private function parseJsonFromText(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract confidence from structured AI response.
     *
     * Priority: real confidence from AI → fallback for missing field → low fallback for no JSON.
     */
    private function extractConfidence(?array $structuredData): float
    {
        if ($structuredData === null) {
            return 0.5;
        }

        if (isset($structuredData['confidence']) && is_numeric($structuredData['confidence'])) {
            return (float) min(1.0, max(0.0, $structuredData['confidence']));
        }

        return 0.6;
    }

    private function detectMediaType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function apiKey(): ?string
    {
        return config('ai.anthropic.api_key');
    }

    private function model(): string
    {
        return config('ai.anthropic.model', 'claude-sonnet-4-5-20250929');
    }

    private function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }

    private function log(
        string $model,
        string $capability,
        int $latencyMs,
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $status = 'success',
        ?string $errorMessage = null,
        ?int $companyId = null,
        ?string $moduleKey = null,
    ): void {
        try {
            AiRequestLog::record(
                provider: 'anthropic',
                model: $model,
                capability: $capability,
                latencyMs: $latencyMs,
                status: $status,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                errorMessage: $errorMessage,
                companyId: $companyId,
                moduleKey: $moduleKey,
            );
        } catch (\Throwable $e) {
            Log::warning('AnthropicAiAdapter: failed to log request', ['error' => $e->getMessage()]);
        }
    }
}
