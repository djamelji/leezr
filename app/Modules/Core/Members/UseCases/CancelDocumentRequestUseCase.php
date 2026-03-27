<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;

/**
 * ADR-395: Cancel a pending document request.
 *
 * Guard: request must be in 'requested' status (not yet submitted).
 * Sets status to 'cancelled', records reviewer.
 */
class CancelDocumentRequestUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Company $company, int $requestId, User $actor): void
    {
        $request = DocumentRequest::where('company_id', $company->id)
            ->where('id', $requestId)
            ->firstOrFail();

        if ($request->status !== DocumentRequest::STATUS_REQUESTED) {
            abort(422, 'Only requests in "requested" status can be cancelled.');
        }

        $request->update([
            'status' => DocumentRequest::STATUS_CANCELLED,
            'reviewer_id' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->logCompany(
            $company->id,
            AuditAction::DOCUMENT_REQUEST_CANCELLED,
            'document_request',
            $request->id,
            ['metadata' => [
                'document_type_id' => $request->document_type_id,
                'user_id' => $request->user_id,
            ]],
        );

        // Notify the member that their request was cancelled
        $recipient = User::find($request->user_id);
        if ($recipient) {
            NotificationDispatcher::send(
                topicKey: 'documents.request_cancelled',
                recipients: [$recipient],
                payload: [
                    'document_type_id' => $request->document_type_id,
                    'link' => '/account-settings/documents',
                ],
                company: $company,
                entityKey: "document_request_cancelled:{$request->id}",
            );
        }
    }
}
