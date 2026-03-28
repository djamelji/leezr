<?php

namespace App\Jobs\Documents;

use App\Core\Ai\AiPolicyResolver;
use App\Core\Ai\DTOs\AiInsight;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentAnalysisResult;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\ImageProcessor;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
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

        // ADR-422: Set ai_status = processing at start
        $document->update(['ai_status' => 'processing']);

        // ADR-413: Resolve AI policy — gate before any analysis
        $policy = AiPolicyResolver::forModule($companyId ?? 0, 'documents');
        if (! $policy->analysisEnabled) {
            Log::info('ProcessDocumentAiJob: AI disabled for company, skipping', [
                'company_id' => $companyId,
            ]);
            $document->update(['ai_status' => 'completed']); // No-op but not failed

            return;
        }

        // Download file to temp
        $filePath = $document->file_path;
        if (! Storage::exists($filePath)) {
            Log::warning('ProcessDocumentAiJob: file not found in storage', ['path' => $filePath]);
            $document->update(['ai_status' => 'failed']);

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
                    'ai_status' => 'completed',
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

            // Step 5: Auto-reject execution (MemberDocument only, ADR-416)
            $autoRejected = false;
            if ($decision->shouldAutoReject && $this->documentClass === MemberDocument::class) {
                $request = DocumentRequest::where('company_id', $document->company_id)
                    ->where('user_id', $document->user_id)
                    ->where('document_type_id', $document->document_type_id)
                    ->where('status', DocumentRequest::STATUS_SUBMITTED)
                    ->first();

                if ($request) {
                    $request->update([
                        'status' => DocumentRequest::STATUS_REJECTED,
                        'reviewer_id' => null, // AI auto-reject
                        'review_note' => 'Auto-rejected by AI: '.$decision->autoRejectReason,
                        'reviewed_at' => now(),
                    ]);
                    $autoRejected = true;

                    Log::info('ProcessDocumentAiJob: auto-rejected document request', [
                        'document_id' => $this->documentId,
                        'request_id' => $request->id,
                        'reason' => $decision->autoRejectReason,
                    ]);

                    // ADR-410 pattern: auto-re-request after rejection
                    $request->update([
                        'status' => DocumentRequest::STATUS_REQUESTED,
                        'requested_at' => now(),
                    ]);
                }
            }

            // Step 6: Post-AI notifications (MemberDocument only, ADR-416)
            if ($this->documentClass === MemberDocument::class) {
                try {
                    $company = Company::find($document->company_id);
                    $targetUser = User::find($document->user_id);
                    $docType = $expectedType ?? DocumentType::find($document->document_type_id);

                    if ($company && $targetUser && $docType) {
                        if ($autoRejected) {
                            // Notify the member: their document was auto-rejected
                            NotificationDispatcher::send(
                                topicKey: 'documents.reviewed',
                                recipients: [$targetUser],
                                payload: [
                                    'document_type' => $docType->label,
                                    'document_code' => $docType->code,
                                    'status' => DocumentRequest::STATUS_REJECTED,
                                    'review_note' => 'Auto-rejected by AI: '.$decision->autoRejectReason,
                                    'link' => '/account-settings/documents',
                                ],
                                company: $company,
                                entityKey: "document_request:{$targetUser->id}:{$docType->code}",
                            );

                            // Notify the member: a new request has been created (re-request)
                            NotificationDispatcher::send(
                                topicKey: 'documents.request_new',
                                recipients: [$targetUser],
                                payload: [
                                    'document_type' => $docType->label,
                                    'document_code' => $docType->code,
                                    'review_note' => 'Auto-rejected by AI: '.$decision->autoRejectReason,
                                    'link' => '/account-settings/documents',
                                ],
                                company: $company,
                                entityKey: "document_request:{$targetUser->id}:{$docType->code}",
                            );
                        } else {
                            // AI analysis complete (no auto-reject) — notify admins/managers
                            $adminUserIds = $company->memberships()
                                ->where(function ($q) {
                                    $q->where('role', 'owner')
                                        ->orWhereHas('companyRole', fn ($r) => $r->where('is_administrative', true));
                                })
                                ->pluck('user_id');

                            if ($adminUserIds->isNotEmpty()) {
                                $adminRecipients = User::whereIn('id', $adminUserIds)->get();
                                NotificationDispatcher::send(
                                    topicKey: 'documents.ai_analyzed',
                                    recipients: $adminRecipients,
                                    payload: [
                                        'document_type' => $docType->label,
                                        'document_code' => $docType->code,
                                        'member_name' => $targetUser->name,
                                        'confidence' => $result->confidence,
                                        'detected_type' => $result->detectedType,
                                        'link' => '/company/documents/requests',
                                    ],
                                    company: $company,
                                    entityKey: "member_document:{$document->id}",
                                );
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Notification failure must NOT fail the job
                    Log::warning('ProcessDocumentAiJob: notification dispatch failed', [
                        'document_id' => $this->documentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Step 7: Build AI suggestions for member profile auto-fill (ADR-426)
            if ($this->documentClass === MemberDocument::class && ! empty($result->fields)) {
                $suggestions = $this->buildSuggestions($result);
                if (! empty($suggestions)) {
                    $document->update(['ai_suggestions' => $suggestions]);
                }
            }

            // ADR-422: Mark completed
            $document->update(['ai_status' => 'completed']);

            Log::info('ProcessDocumentAiJob: analysis + decision complete', [
                'class' => $this->documentClass,
                'id' => $this->documentId,
                'source' => $result->source,
                'confidence' => $result->confidence,
                'detected_type' => $result->detectedType,
                'has_action' => $decision->hasAnyAction(),
                'auto_rejected' => $autoRejected,
                'insights_count' => count($decision->insights),
            ]);
        } catch (\Throwable $e) {
            $document->update(['ai_status' => 'failed']);

            throw $e;
        } finally {
            @unlink($tempPath);
            foreach ($tempImages as $img) {
                @unlink($img);
            }
        }
    }

    /**
     * ADR-426: Build profile auto-fill suggestions from AI-extracted fields.
     *
     * Maps document fields (last_name, first_name, birth_date, etc.)
     * to member profile field codes. Only includes fields with non-null values.
     *
     * @return array<int, array{field: string, value: string, confidence: float}>
     */
    private function buildSuggestions(DocumentAnalysisResult $result): array
    {
        // Map of AI field keys → profile field codes
        // Only include fields that map to actual FieldDefinition codes
        $mappableFields = [
            'last_name' => 'last_name',
            'first_name' => 'first_name',
            'birth_date' => 'birth_date',
            'place_of_birth' => 'place_of_birth',
            'nationality' => 'nationality',
            'address' => 'address',
            'gender' => 'gender',
            'document_number' => 'document_number',
        ];

        $suggestions = [];
        $confidence = $result->confidence;

        foreach ($result->fields as $key => $value) {
            if ($value === null || $value === '' || ! isset($mappableFields[$key])) {
                continue;
            }

            $suggestions[] = [
                'field' => $mappableFields[$key],
                'value' => (string) $value,
                'confidence' => round($confidence, 2),
            ];
        }

        return $suggestions;
    }

    public function failed(\Throwable $exception): void
    {
        // ADR-422: Mark failed on permanent failure
        $document = ($this->documentClass)::find($this->documentId);
        $document?->update(['ai_status' => 'failed']);

        Log::error('ProcessDocumentAiJob: failed permanently', [
            'class' => $this->documentClass,
            'id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
