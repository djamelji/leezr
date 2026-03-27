<?php

namespace App\Jobs\Documents;

use App\Core\Ai\AiPolicyResolver;
use App\Core\Ai\DTOs\AiInsight;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentAnalysisResult;
use App\Core\Documents\DocumentType;
use App\Core\Documents\ImageProcessor;
use App\Core\Documents\MemberDocument;
use App\Modules\Core\Documents\Services\DocumentAiAnalysisService;
use App\Modules\Core\Documents\Services\DocumentAiDecisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Async AI analysis job dispatched after document upload.
 * Runs the MRZ → AI → OCR cascade and stores results.
 *
 * Safe to run with NullAdapter (no-op, terminates cleanly).
 */
class ProcessDocumentAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public function __construct(
        private readonly string $documentClass,
        private readonly int $documentId,
        private readonly ?int $documentTypeId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(DocumentAiAnalysisService $service): void
    {
        /** @var MemberDocument|CompanyDocument $document */
        $document = ($this->documentClass)::find($this->documentId);

        if (! $document) {
            Log::info('ProcessDocumentAiJob: document not found, skipping', [
                'class' => $this->documentClass,
                'id' => $this->documentId,
            ]);

            return;
        }

        $companyId = $document->company_id ?? null;

        // ADR-413: Resolve AI policy — gate before any analysis
        $policy = AiPolicyResolver::forModule($companyId ?? 0, 'documents');
        if (! $policy->analysisEnabled) {
            Log::info('ProcessDocumentAiJob: AI disabled for company, skipping', [
                'company_id' => $companyId,
            ]);

            return;
        }

        // Download file to temp
        $filePath = $document->file_path;
        if (! Storage::exists($filePath)) {
            Log::warning('ProcessDocumentAiJob: file not found in storage', ['path' => $filePath]);

            return;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $tempPath = sys_get_temp_dir().'/'.uniqid('ai_doc_').'.'.$ext;
        file_put_contents($tempPath, Storage::get($filePath));

        // Anthropic reads PDF natively. Local adapters (Ollama, LM Studio) need image conversion.
        $imagePath = $tempPath;
        $tempImages = [];
        if ($ext === 'pdf') {
            $adapter = \App\Core\Ai\AiGatewayManager::adapterForCapability(
                \App\Core\Ai\DTOs\AiCapability::Vision
            );
            if (in_array($adapter->key(), ['ollama', 'lmstudio'], true)) {
                $pages = app(ImageProcessor::class)->pdfToImages($tempPath);
                if (! empty($pages)) {
                    $imagePath = $pages[0];
                    $tempImages = $pages;
                } else {
                    Log::warning('ProcessDocumentAiJob: PDF→image conversion failed, skipping AI vision', [
                        'path' => $filePath,
                    ]);
                    $imagePath = null;
                }
            }
        }

        try {
            $expectedType = $this->documentTypeId
                ? DocumentType::find($this->documentTypeId)
                : null;

            // If PDF→image conversion failed, store empty result and bail
            if ($imagePath === null) {
                $document->update([
                    'ai_analysis' => (new DocumentAnalysisResult(
                        detectedType: null,
                        fields: [],
                        expiryDate: null,
                        confidence: 0,
                        source: 'none',
                        validationErrors: ['PDF to image conversion failed — AI vision skipped'],
                    ))->toArray(),
                ]);

                return;
            }

            // Step 1: Analysis (existing)
            $result = $service->analyze(
                imagePath: $imagePath,
                ocrText: $document->ocr_text,
                expectedType: $expectedType,
                companyId: $companyId,
            );

            $document->update([
                'ai_analysis' => $result->toArray(),
            ]);

            // Step 2: Decision — returns intentions only (ADR-413)
            $decision = app(DocumentAiDecisionService::class)->evaluate(
                policy: $policy,
                result: $result,
                expectedTypeCode: $expectedType?->code,
                hasExpiryDate: ! empty($document->expires_at),
            );

            // Step 3: Execute intentions (mutations happen HERE, not in DecisionService)
            if ($decision->shouldAutoFillExpiry && $decision->detectedExpiryDate) {
                $document->update(['expires_at' => Carbon::parse($decision->detectedExpiryDate)]);
            }

            // Step 4: Store insights
            if (! empty($decision->insights)) {
                $document->update([
                    'ai_insights' => array_map(fn (AiInsight $i) => $i->toArray(), $decision->insights),
                ]);
            }

            Log::info('ProcessDocumentAiJob: analysis + decision complete', [
                'class' => $this->documentClass,
                'id' => $this->documentId,
                'source' => $result->source,
                'confidence' => $result->confidence,
                'detected_type' => $result->detectedType,
                'has_action' => $decision->hasAnyAction(),
                'insights_count' => count($decision->insights),
            ]);
        } finally {
            @unlink($tempPath);
            foreach ($tempImages as $img) {
                @unlink($img);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDocumentAiJob: failed permanently', [
            'class' => $this->documentClass,
            'id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
