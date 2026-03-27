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
 * Ollama AI adapter — self-hosted LLM inference via Ollama REST API.
 *
 * Supports: text completion, vision (multimodal), text extraction.
 * Retry: 3 attempts with exponential backoff [1s, 2s, 4s].
 */
class OllamaAiAdapter implements AiProviderAdapter
{
    public function key(): string
    {
        return 'ollama';
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
        try {
            $response = Http::timeout(5)
                ->get($this->host().'/api/tags');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function healthCheck(): AiHealthResult
    {
        $start = microtime(true);

        try {
            $response = Http::timeout(5)
                ->get($this->host().'/api/tags');

            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                return new AiHealthResult(
                    status: 'down',
                    message: 'Ollama returned HTTP '.$response->status(),
                    latencyMs: $latencyMs,
                );
            }

            $models = collect($response->json('models', []));
            $requiredModels = array_filter([
                config('ai.ollama.model'),
                config('ai.ollama.vision_model'),
            ]);

            $installedNames = $models->pluck('name')->map(fn ($n) => explode(':', $n)[0])->all();

            $missing = array_diff($requiredModels, $installedNames);

            if (! empty($missing)) {
                return new AiHealthResult(
                    status: 'degraded',
                    message: 'Missing models: '.implode(', ', $missing),
                    latencyMs: $latencyMs,
                );
            }

            return new AiHealthResult(
                status: 'healthy',
                message: $models->count().' model(s) available',
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
        $model = $options['model'] ?? config('ai.ollama.model', 'glm-ocr');

        return $this->generate($model, $prompt, null, $options);
    }

    public function vision(string $imagePath, string $prompt, array $options = []): AiResponse
    {
        $model = $options['model'] ?? config('ai.ollama.vision_model', 'qwen2.5-vl');

        if (! file_exists($imagePath)) {
            Log::warning('OllamaAiAdapter: image not found', ['path' => $imagePath]);

            return AiResponse::empty('ollama');
        }

        $imageBase64 = base64_encode(file_get_contents($imagePath));

        return $this->generate($model, $prompt, [$imageBase64], $options);
    }

    public function extractText(string $imagePath, array $options = []): AiResponse
    {
        return $this->vision(
            $imagePath,
            'Extract all visible text from this image. Return the text exactly as written, preserving layout and structure.',
            $options,
        );
    }

    /**
     * Core Ollama /api/generate call with retry.
     */
    private function generate(string $model, string $prompt, ?array $images, array $options): AiResponse
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
        ];

        if ($images) {
            $payload['images'] = $images;
        }

        if (isset($options['format'])) {
            $payload['format'] = $options['format'];
        }

        $maxAttempts = 3;
        $backoff = [1, 2, 4];
        $timeout = $options['timeout'] ?? config('ai.ollama.timeout', 60);

        $lastError = null;
        $start = microtime(true);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->post($this->host().'/api/generate', $payload);

                $latencyMs = (int) ((microtime(true) - $start) * 1000);

                if ($response->successful()) {
                    $data = $response->json();
                    $text = $data['response'] ?? '';

                    // Try to extract JSON from response
                    $structuredData = $this->parseJsonFromText($text);

                    // Extract confidence from model's JSON response (ADR-416)
                    $confidence = 0.5;
                    if ($structuredData && isset($structuredData['confidence'])) {
                        $confidence = max(0, min(1, (float) $structuredData['confidence']));
                    } elseif ($structuredData) {
                        $confidence = 0.6;
                    }

                    $aiResponse = new AiResponse(
                        text: $text,
                        structuredData: $structuredData,
                        confidence: $confidence,
                        tokensUsed: ($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0),
                        latencyMs: $latencyMs,
                        model: $model,
                        provider: 'ollama',
                    );

                    $this->log(
                        model: $model,
                        capability: $images ? 'vision' : 'completion',
                        latencyMs: $latencyMs,
                        inputTokens: $data['prompt_eval_count'] ?? 0,
                        outputTokens: $data['eval_count'] ?? 0,
                        companyId: $options['company_id'] ?? null,
                        moduleKey: $options['module_key'] ?? null,
                    );

                    return $aiResponse;
                }

                $lastError = 'HTTP '.$response->status().': '.$response->body();
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

        Log::warning('OllamaAiAdapter: all attempts failed', [
            'model' => $model,
            'error' => $lastError,
            'attempts' => $maxAttempts,
        ]);

        $this->log(
            model: $model,
            capability: $images ? 'vision' : 'completion',
            latencyMs: $latencyMs,
            status: 'error',
            errorMessage: $lastError,
            companyId: $options['company_id'] ?? null,
            moduleKey: $options['module_key'] ?? null,
        );

        return AiResponse::empty('ollama');
    }

    /**
     * Try to extract a JSON object from LLM text output.
     */
    private function parseJsonFromText(string $text): ?array
    {
        // Try direct parse
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try extracting JSON from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try extracting first JSON object
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function host(): string
    {
        return rtrim(config('ai.ollama.host', 'http://localhost:11434'), '/');
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
                provider: 'ollama',
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
            Log::warning('OllamaAiAdapter: failed to log request', ['error' => $e->getMessage()]);
        }
    }
}
