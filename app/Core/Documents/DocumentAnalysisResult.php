<?php

namespace App\Core\Documents;

/**
 * Result of AI document analysis.
 * Contains structured fields extracted from the document.
 */
final class DocumentAnalysisResult
{
    public function __construct(
        public readonly ?string $detectedType,     // cni, passport, driving_license, kbis, etc.
        public readonly array $fields,             // Extracted key-value pairs
        public readonly ?string $expiryDate,       // YYYY-MM-DD if detected
        public readonly float $confidence,
        public readonly string $source,            // 'mrz', 'ai', 'ocr'
        public readonly array $validationErrors = [],
        public readonly ?string $correctedText = null,  // AI-corrected text from document
        public readonly ?string $summary = null,        // 1-2 sentence document description
        public readonly ?bool $isExpired = null,        // AI-determined expiry status
    ) {}

    public function toArray(): array
    {
        return [
            'detected_type' => $this->detectedType,
            'fields' => $this->fields,
            'expiry_date' => $this->expiryDate,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'validation_errors' => $this->validationErrors,
            'corrected_text' => $this->correctedText,
            'summary' => $this->summary,
            'is_expired' => $this->isExpired,
        ];
    }

    public static function empty(): self
    {
        return new self(
            detectedType: null,
            fields: [],
            expiryDate: null,
            confidence: 0.0,
            source: 'none',
        );
    }
}
