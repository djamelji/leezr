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

        // Step 2: AI Vision — if adapter available (pass OCR text for cross-reference)
        $aiResult = $this->tryAiVision($imagePath, $expectedType, $companyId, $ocrText);
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
    private function tryAiVision(string $imagePath, ?DocumentType $expectedType, ?int $companyId, ?string $ocrText = null): ?DocumentAnalysisResult
    {
        $adapter = AiGatewayManager::adapterForCapability(AiCapability::Vision);

        if ($adapter->key() === 'null') {
            return null;
        }

        try {
            $prompt = $this->buildVisionPrompt($expectedType, $ocrText);

            $response = $adapter->vision($imagePath, $prompt, [
                'format' => 'json',
                'company_id' => $companyId,
                'module_key' => 'documents',
            ]);

            if (! $response->structuredData) {
                return null;
            }

            $data = $response->structuredData;
            $fields = $data['fields'] ?? $data;

            // Capture corrected_text in fields if returned by AI
            if (! empty($data['corrected_text']) && is_array($fields)) {
                $fields['corrected_text'] = $data['corrected_text'];
            }

            return new DocumentAnalysisResult(
                detectedType: $data['document_type'] ?? null,
                fields: $fields,
                expiryDate: $data['expiry_date'] ?? ($data['fields']['expiry_date'] ?? null),
                confidence: $response->confidence,
                source: 'ai',
                validationErrors: $this->validateAiResult($data, $expectedType),
                correctedText: $data['corrected_text'] ?? null,
                summary: $data['summary'] ?? null,
                isExpired: isset($data['is_expired']) ? (bool) $data['is_expired'] : null,
            );
        } catch (\Throwable $e) {
            Log::warning('DocumentAiAnalysis: AI vision failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the AI vision prompt (business logic — lives HERE, not in adapter).
     */
    private function buildVisionPrompt(?DocumentType $expectedType, ?string $ocrText = null): string
    {
        $today = now()->format('Y-m-d');

        $base = "Analyze this document image. Return ONLY a valid JSON object (no extra text) with these fields:
- \"document_type\": the detected type. Use EXACTLY one of: \"cni\" (national ID card), \"passport\", \"driving_license\", \"residence_permit\", \"kbis\" (company registration), \"rib\" (bank details), \"attestation\", \"invoice\", \"payslip\", \"other\"
- \"fields\": extracted information: \"last_name\", \"first_name\", \"birth_date\", \"document_number\", \"expiry_date\", \"issuing_authority\", \"address\", \"nationality\", \"place_of_birth\", \"gender\" (use null for fields you cannot read clearly)
- \"expiry_date\": expiration date in YYYY-MM-DD format if visible, or null
- \"confidence\": your confidence from 0.0 to 1.0
- \"corrected_text\": the full text content of the document, cleaned and structured (fix OCR errors, correct spelling, preserve layout with newlines). If no text is readable, use null
- \"summary\": a 1-2 sentence description of the document in French (e.g. \"Carte nationale d'identite de Jean Dupont, valide jusqu'au 15/03/2030.\")
- \"is_expired\": boolean — true if the document has an expiry date AND that date is before {$today}, false if not expired or no expiry date visible

DOCUMENT IDENTIFICATION HINTS (French administrative documents):
- \"cni\" (Carte Nationale d'Identite): says \"CARTE NATIONALE D'IDENTITE\" or \"REPUBLIQUE FRANCAISE\", credit-card size, blue/white/red. New format (2021+): credit-card size with biometric chip. Old format: larger laminated card.
- \"driving_license\": says \"PERMIS DE CONDUIRE\", pink/EU format, has vehicle categories (A, B, C, D, E). Shows issue date per category.
- \"passport\": booklet format, says \"PASSEPORT\", has MRZ lines at bottom (2 lines of 44 characters or 3 lines of 30 characters).
- \"residence_permit\" (titre de sejour): says \"TITRE DE SEJOUR\", \"CARTE DE RESIDENT\", or \"CARTE DE SEJOUR\". May show \"AUTORISE SON TITULAIRE A TRAVAILLER\".
- \"kbis\": says \"EXTRAIT K BIS\" or \"GREFFE DU TRIBUNAL DE COMMERCE\". Contains SIREN/SIRET, legal form, registered address.
- \"rib\" (releve d'identite bancaire): contains IBAN, BIC/SWIFT, bank name, account holder.
- \"attestation\": official certificate — employer attestation, insurance, social security (Attestation Securite Sociale, Attestation Pole Emploi).
- \"payslip\" (bulletin de paie): says \"BULLETIN DE PAIE\" or \"BULLETIN DE SALAIRE\", monthly salary breakdown, employer/employee info.
- \"invoice\" (facture): says \"FACTURE\", contains amount due, invoice number, billing period, company details.

CRITICAL DISAMBIGUATION:
- A CNI is NOT a driving license — both have a photo and name but the header text is completely different.
- A passport photo page is NOT a CNI — passports have MRZ lines, CNIs do not (or have shorter ones).
- An attestation is NOT an ID card — attestations are plain text documents, ID cards have a photo.
- A RIB is NOT an invoice — RIBs show bank details, invoices show amounts due.

CONFIDENCE RULES (strict — do NOT inflate):
- 0.9-1.0: Document type is 100% certain (header text matches exactly) AND all key identity fields are clearly legible (no guessing).
- 0.7-0.8: Document type is certain but 1-2 fields are partially obscured, blurry, or truncated. You can still read most fields.
- 0.5-0.6: Document type is likely correct but image quality is mediocre (glare, shadow, angle). Several fields are hard to read.
- 0.3-0.4: Document type is uncertain — you are guessing based on layout/format. Poor image quality.
- 0.1-0.2: Wrong document type detected vs expected, OR the image is barely readable, OR this is clearly not a document (photo of a person, blank page, etc.).
- 0.0: Not a document at all, completely unreadable, or the image is corrupted/empty.
NEVER return 0.95+ unless EVERY key field is perfectly legible. When in doubt, round DOWN.";

        // Include OCR text as reference for cross-verification
        if ($ocrText && strlen(trim($ocrText)) > 20) {
            $truncatedOcr = mb_substr(trim($ocrText), 0, 2000);
            $base .= "\n\nPRE-EXTRACTED OCR TEXT (may contain errors — use the image as ground truth):\n\"\"\"\n{$truncatedOcr}\n\"\"\"\nUse this OCR text as a REFERENCE to help identify field values, but ALWAYS verify against the image. If the OCR text contains errors (wrong characters, garbled text, missing accents), correct them in your response. The \"corrected_text\" field should contain the cleaned version.";
        }

        if ($expectedType) {
            $base .= "\n\nEXPECTED TYPE: \"{$expectedType->label}\" (code: {$expectedType->code}).";
            $base .= "\nIf this document is NOT a {$expectedType->label}, return the REAL detected type and set confidence below 0.2.";
            $base .= "\nDo NOT force-match: a CNI is not a driving_license, an invoice is not an ID card.";
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
