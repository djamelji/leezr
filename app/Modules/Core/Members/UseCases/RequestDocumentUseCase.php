<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;

/**
 * ADR-192 / ADR-759 (M2): Create a single document request for a SUBJECT (user OR account-less
 * employee). Délègue au DocumentRequestService (événements append-only + garde « demande active »).
 * Accepte un message, une échéance et le flag de validation requise.
 */
class RequestDocumentUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentRequestService $requests,
    ) {}

    public function execute(
        int $companyId,
        DocumentSubject $subject,
        string $documentTypeCode,
        ?User $actor = null,
        ?string $message = null,
        ?string $dueDate = null,
        bool $requiresValidation = true,
    ): DocumentRequest {
        \App\Core\Documents\Services\PersonDocumentContinuity::settleSubject($companyId, $subject); // ADR-770

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

        // Ouvre (ou ré-ouvre) la demande — le service refuse si une demande est déjà active.
        $request = $this->requests->open($companyId, $subject, $docType, $actor, $message, $dueDate, $requiresValidation);

        $this->audit->logCompany(
            $companyId,
            AuditAction::DOCUMENT_REQUESTED,
            'document_request',
            (string) $request->id,
            ['metadata' => ['document_type_code' => $documentTypeCode, 'subject' => (string) $subject]],
        );

        // ADR-389: notifier uniquement un collaborateur avec compte.
        if ($subject->is(DocumentSubject::TYPE_USER)) {
            $recipient = User::find($subject->id);
            if ($recipient) {
                NotificationDispatcher::send(
                    topicKey: 'documents.request_new',
                    recipients: [$recipient],
                    payload: [
                        'document_type' => $docType->label,
                        'document_code' => $documentTypeCode,
                        'message' => $message,
                        'link' => '/account-settings/documents',
                    ],
                    company: Company::find($companyId),
                    entityKey: "document_request:{$subject->id}:{$documentTypeCode}",
                );
            }
        }

        return $request;
    }
}
