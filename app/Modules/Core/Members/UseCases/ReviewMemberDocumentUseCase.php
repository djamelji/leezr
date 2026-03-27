<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use Illuminate\Validation\ValidationException;

/**
 * ADR-176: Review (approve/reject) a member document request.
 *
 * Pipeline: actor membership → target membership → type → scope guard
 * → load request → transition guard → update → result.
 */
class ReviewMemberDocumentUseCase
{
    public function execute(ReviewMemberDocumentData $data): ReviewMemberDocumentResult
    {
        // 1. Verify actor membership (defense in depth)
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load target membership
        $membership = $data->company->memberships()->findOrFail($data->membershipId);
        $targetUser = User::findOrFail($membership->user_id);

        // 3. Load DocumentType by code
        $type = DocumentType::where('code', $data->documentCode)->firstOrFail();

        // 4. Scope guard: only company_user documents have review workflow
        if ($type->scope !== DocumentType::SCOPE_COMPANY_USER) {
            throw ValidationException::withMessages([
                'document_code' => ['Review is only available for member documents.'],
            ]);
        }

        // 5. Load DocumentRequest
        $request = DocumentRequest::where('company_id', $data->company->id)
            ->where('user_id', $targetUser->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        // 6. Transition guards
        if ($data->status === DocumentRequest::STATUS_APPROVED) {
            // Must have a file
            $hasFile = MemberDocument::where('company_id', $data->company->id)
                ->where('user_id', $targetUser->id)
                ->where('document_type_id', $type->id)
                ->exists();

            if (! $hasFile) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot approve: no file has been submitted.'],
                ]);
            }

            if ($request->status !== DocumentRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ['Can only approve submitted documents.'],
                ]);
            }
        }

        if ($data->status === DocumentRequest::STATUS_REJECTED) {
            if ($request->status !== DocumentRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ['Can only reject submitted documents.'],
                ]);
            }
        }

        // 8. Update request
        $request->update([
            'status' => $data->status,
            'reviewer_id' => $data->actor->id,
            'review_note' => $data->reviewNote,
            'reviewed_at' => now(),
        ]);

        // 9. ADR-389: Notify the member about the review outcome
        NotificationDispatcher::send(
            topicKey: 'documents.reviewed',
            recipients: [$targetUser],
            payload: [
                'document_type' => $type->label,
                'document_code' => $type->code,
                'status' => $data->status,
                'review_note' => $data->reviewNote,
                'link' => '/account-settings/documents',
            ],
            company: $data->company,
            entityKey: "document_request:{$targetUser->id}:{$type->code}",
        );

        // 10. ADR-410: Rejection auto-re-requests — reset to requested + notify
        if ($data->status === DocumentRequest::STATUS_REJECTED) {
            $request->update([
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);

            NotificationDispatcher::send(
                topicKey: 'documents.request_new',
                recipients: [$targetUser],
                payload: [
                    'document_type' => $type->label,
                    'document_code' => $type->code,
                    'review_note' => $data->reviewNote,
                    'link' => '/account-settings/documents',
                ],
                company: $data->company,
                entityKey: "document_request:{$targetUser->id}:{$type->code}",
            );
        }

        // 11. Return result
        return new ReviewMemberDocumentResult(
            code: $type->code,
            status: $request->status,
            reviewNote: $request->review_note,
            reviewedAt: $request->reviewed_at->toIso8601String(),
        );
    }
}
