<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;

/**
 * ADR-848 (Leezr 4.2 M9) — manual reminder of a document request, moved verbatim from DocumentRequestController::remind.
 * ADR-770 : a manual reminder is TRACED (event `reminded`, `last_reminded_at`) and never rewrites `requested_at`.
 * Returns whether someone was notified (a person without an account gets nothing — never a false confirmation).
 */
class RemindDocumentRequestUseCase
{
    public function execute(Company $company, int $requestId, User $actor): bool
    {
        $docRequest = DocumentRequest::where('company_id', $company->id)
            ->where('id', $requestId)
            ->firstOrFail();

        if ($docRequest->status !== DocumentRequest::STATUS_REQUESTED) {
            abort(422, 'Only requests in "requested" status can be reminded.');
        }

        $docRequest->update(['last_reminded_at' => now()]);
        DocumentRequestEvent::create([
            'company_id' => $company->id, 'document_request_id' => $docRequest->id,
            'event' => DocumentRequestEvent::E_REMINDED, 'actor_id' => $actor->id,
            'created_at' => now(), 'metadata' => ['tier' => 'manual'],
        ]);

        $recipient = User::find($docRequest->user_id);
        if ($recipient) {
            $docType = DocumentType::find($docRequest->document_type_id);
            NotificationDispatcher::send(
                topicKey: 'documents.request_new',
                recipients: [$recipient],
                payload: [
                    'document_type' => $docType?->label,
                    'document_code' => $docType?->code,
                    'link' => '/account-settings/documents',
                ],
                company: $company,
                entityKey: "document_request:{$recipient->id}:{$docType?->code}",
            );
        }

        return (bool) $recipient;
    }
}
