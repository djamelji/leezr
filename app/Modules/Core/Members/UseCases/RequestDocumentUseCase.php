<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;

/**
 * ADR-192: Create a single document request for a member.
 *
 * Guards:
 * - DocumentTypeActivation must be enabled for the company
 * - No active (requested/submitted) request for same (company, user, doc_type)
 */
class RequestDocumentUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $companyId, int $userId, string $documentTypeCode): DocumentRequest
    {
        $docType = DocumentType::where('code', $documentTypeCode)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->active()
            ->firstOrFail();

        $activation = DocumentTypeActivation::where('company_id', $companyId)
            ->where('document_type_id', $docType->id)
            ->where('enabled', true)
            ->first();

        if (! $activation) {
            abort(422, 'Document type is not activated for this company.');
        }

        $existing = DocumentRequest::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('document_type_id', $docType->id)
            ->first();

        if ($existing && in_array($existing->status, [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED])) {
            abort(422, 'An active document request already exists for this member.');
        }

        // Reuse closed row (UNIQUE constraint) or create new
        if ($existing) {
            $existing->update([
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now(),
                'submitted_at' => null,
                'reviewed_at' => null,
                'reviewer_id' => null,
                'review_note' => null,
            ]);
            $request = $existing;
        } else {
            $request = DocumentRequest::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'document_type_id' => $docType->id,
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);
        }

        $this->audit->logCompany(
            $companyId,
            AuditAction::DOCUMENT_REQUESTED,
            'document_request',
            (string) $request->id,
            ['metadata' => ['document_type_code' => $documentTypeCode, 'user_id' => $userId]],
        );

        // ADR-389: Notify the target member
        $recipient = User::find($userId);
        if ($recipient) {
            NotificationDispatcher::send(
                topicKey: 'documents.request_new',
                recipients: [$recipient],
                payload: [
                    'document_type' => $docType->label,
                    'document_code' => $documentTypeCode,
                    'link' => '/account-settings/documents',
                ],
                company: Company::find($companyId),
                entityKey: "document_request:{$userId}:{$documentTypeCode}",
            );
        }

        return $request;
    }
}
