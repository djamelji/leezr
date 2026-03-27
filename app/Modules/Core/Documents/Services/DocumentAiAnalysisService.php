<?php

namespace App\Modules\Core\Documents\Services;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\DTOs\AiCapability;
use App\Core\Documents\DocumentAnalysisResult;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MrzParser;
use App\Core\Documents\MrzResult;
use Illuminate\Support\Facades\Log;

/**
 * Document AI analysis orchestration service.
 * ALL business logic lives here — Core AI is strictly neutral.
 *
 * Cascade: MRZ (truth) → AI Vision (enrichment) → OCR (fallback)
 */
class DocumentAiAnalysisService
{
    public function __construct(
        private readonly MrzParser $mrzParser,
    ) {}

    /**
     * Analyze a document image using the cascade strategy.
     *
     * @param  string  $imagePath  Path to the document image
     * @param  string|null  $ocrText  Pre-extracted OCR text (from Tesseract)
     * @param  DocumentType|null  $expectedType  Expected document type for validation
     * @param  int|null  $companyId  For logging and feature gating
     */
    public function analyze(
        string $imagePath,
        ?string $ocrText = null,
        ?DocumentType $expectedType = null,
        ?int $companyId = null,
    ): DocumentAnalysisResult {
        // Step 1: MRZ — instant, 100% reliable, free
        if ($ocrText) {
            $mrzResult = $this->tryMrz($ocrText, $expectedType);
            if ($mrzResult) {
                return $mrzResult;
            }
        }

        // Step 2: AI Vision — if adapter available
        $aiResult = $this->tryAiVision($imagePath, $expectedType, $companyId);
        if ($aiResult) {
            return $aiResult;
        }

        // Step 3: OCR fallback — use existing OCR text as-is
        if ($ocrText && strlen(trim($ocrText)) > 10) {
            return new DocumentAnalysisResult(
                detectedType: null,
                fields: ['raw_text' => $ocrText],
                expiryDate: $this->extractDateFromText($ocrText),
                confidence: 0.2,
                source: 'ocr',
            );
        }

        return DocumentAnalysisResult::empty();
    }

    /**
     * Step 1: Try MRZ extraction from OCR text.
     */
    private function tryMrz(string $ocrText, ?DocumentType $expectedType): ?DocumentAnalysisResult
    {
        $mrz = $this->mrzParser->parse($ocrText);

        if (! $mrz) {
            return null;
        }

        Log::info('DocumentAiAnalysis: MRZ detected', $mrz->toArray());

        $validationErrors = $this->validateMrz($mrz, $expectedType);

        return new DocumentAnalysisResult(
            detectedType: $this->mapMrzType($mrz->documentType),
            fields: $mrz->toArray(),
            expiryDate: $mrz->expiryDate,
            confidence: 1.0,
            source: 'mrz',
            validationErrors: $validationErrors,
        );
    }

    /**
     * Step 2: Try AI Vision analysis.
     */
    private function tryAiVision(string $imagePath, ?DocumentType $expectedType, ?int $companyId): ?DocumentAnalysisResult
    {
        $adapter = AiGatewayManager::adapterForCapability(AiCapability::Vision);

        if ($adapter->key() === 'null') {
            return null;
        }

        try {
            $prompt = $this->buildVisionPrompt($expectedType);

            $response = $adapter->vision($imagePath, $prompt, [
                'format' => 'json',
                'company_id' => $companyId,
                'module_key' => 'documents',
            ]);

            if (! $response->structuredData) {
                return null;
            }

            $data = $response->structuredData;

            return new DocumentAnalysisResult(
                detectedType: $data['document_type'] ?? null,
                fields: $data['fields'] ?? $data,
                expiryDate: $data['expiry_date'] ?? ($data['fields']['expiry_date'] ?? null),
                confidence: $response->confidence,
                source: 'ai',
                validationErrors: $this->validateAiResult($data, $expectedType),
            );
        } catch (\Throwable $e) {
            Log::warning('DocumentAiAnalysis: AI vision failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the AI vision prompt (business logic — lives HERE, not in adapter).
     */
    private function buildVisionPrompt(?DocumentType $expectedType): string
    {
        $base = 'Analyze this document image. Return ONLY a valid JSON object (no extra text) with these fields:
- "document_type": the detected type (e.g. "cni", "passport", "driving_license", "kbis", "rib", "attestation", "invoice", "other")
- "fields": extracted information: "last_name", "first_name", "birth_date", "document_number", "expiry_date", "issuing_authority", "address" (use null for unreadable fields)
- "expiry_date": expiration date in YYYY-MM-DD format if visible, or null
- "confidence": your confidence from 0.0 to 1.0 — be honest and strict

CONFIDENCE RULES:
- 0.9-1.0: Document clearly matches, all fields legible
- 0.6-0.8: Document matches but some fields unclear
- 0.3-0.5: Uncertain, low quality or partial document
- 0.0-0.2: Wrong document type or unreadable';

        if ($expectedType) {
            $base .= "\n\nIMPORTANT: This document SHOULD be a \"{$expectedType->label}\" (code: {$expectedType->code}).";
            $base .= "\nIf this document is clearly NOT a {$expectedType->label} (e.g. it's an invoice, a receipt, or a different document type), set confidence to 0.1 or lower.";
            $base .= "\nReturn the actual detected document_type regardless of what was expected.";
        }

        return $base;
    }

    private function validateMrz(MrzResult $mrz, ?DocumentType $expectedType): array
    {
        $errors = [];

        // Check expiry
        if ($mrz->expiryDate && $mrz->expiryDate < now()->format('Y-m-d')) {
            $errors[] = 'Document expired: '.$mrz->expiryDate;
        }

        return $errors;
    }

    private function validateAiResult(array $data, ?DocumentType $expectedType): array
    {
        $errors = [];

        // Check expiry
        $expiry = $data['expiry_date'] ?? ($data['fields']['expiry_date'] ?? null);
        if ($expiry && $expiry < now()->format('Y-m-d')) {
            $errors[] = 'Document expired: '.$expiry;
        }

        // Check type mismatch
        if ($expectedType && isset($data['document_type'])) {
            $detected = strtolower($data['document_type']);
            $expected = strtolower($expectedType->code);
            if ($detected !== $expected && ! str_contains($detected, $expected)) {
                $errors[] = "Type mismatch: expected {$expected}, detected {$detected}";
            }
        }

        return $errors;
    }

    /**
     * Map MRZ document type code to our internal type.
     */
    private function mapMrzType(?string $mrzType): ?string
    {
        return match ($mrzType) {
            'P' => 'passport',
            'I', 'ID' => 'cni',
            'AC' => 'crew_member',
            'V' => 'visa',
            default => $mrzType ? strtolower($mrzType) : null,
        };
    }

    /**
     * Try to extract a date from raw OCR text (basic regex).
     */
    private function extractDateFromText(string $text): ?string
    {
        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $text, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        return null;
    }
}
