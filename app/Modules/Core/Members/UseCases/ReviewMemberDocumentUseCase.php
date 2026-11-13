<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use Illuminate\Validation\ValidationException;

/**
 * ADR-176 / ADR-759 (M2): Review (approve/reject) a member document request.
 *
 * Délègue la transition au DocumentRequestService (événements append-only + lien version validée).
 * Le refus CONSERVE son motif et RESTE l'état courant (plus d'auto-re-request) — le collaborateur
 * peut re-soumettre. Pipeline : actor → target → type → scope → request → transition → notify.
 */
class ReviewMemberDocumentUseCase
{
    public function __construct(private readonly DocumentRequestService $requests)
    {
    }

    public function execute(ReviewMemberDocumentData $data): ReviewMemberDocumentResult
    {
        // 1. Verify actor membership (defense in depth)
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load target membership
        $membership = $data->company->memberships()->findOrFail($data->membershipId);
        $targetUser = User::findOrFail($membership->user_id);
        \App\Core\Documents\Services\PersonDocumentContinuity::adopt($data->company, $targetUser); // ADR-770 : valider LA pièce de la personne

        // 3. Load DocumentType by code
        $type = DocumentType::where('code', $data->documentCode)->firstOrFail();

        // 4. Scope guard: only company_user documents have review workflow
        if ($type->scope !== DocumentType::SCOPE_COMPANY_USER) {
            throw ValidationException::withMessages([
                'document_code' => ['Review is only available for member documents.'],
            ]);
        }

        // 5. Load DocumentRequest (sujet = user, tolérant aux lignes legacy)
        $request = $this->requests->findExisting($data->company->id, DocumentSubject::user($targetUser->id), $type->id);
        abort_if($request === null, 404);

        // 6. Transition via le moteur (événements + lien version + refus persistant).
        if ($data->status === DocumentRequest::STATUS_APPROVED) {
            // Tolérant aux documents legacy (sujet non renseigné) : match sujet OU user_id.
            $document = MemberDocument::where('company_id', $data->company->id)
                ->where('document_type_id', $type->id)
                ->where(function ($w) use ($targetUser) {
                    $w->where(function ($a) use ($targetUser) {
                        $a->where('document_subject_type', DocumentSubject::TYPE_USER)->where('document_subject_id', $targetUser->id);
                    })->orWhere('user_id', $targetUser->id);
                })
                ->first();

            if ($document === null || empty($document->file_path)) {
                throw ValidationException::withMessages(['status' => ['Cannot approve: no file has been submitted.']]);
            }

            $this->requests->approve($request, $data->actor, $document);
        } else {
            $this->requests->reject($request, $data->actor, (string) $data->reviewNote);
        }

        // 7. ADR-389: Notify the member about the review outcome
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

        $request->refresh();

        return new ReviewMemberDocumentResult(
            code: $type->code,
            status: $request->status,
            reviewNote: $request->review_note,
            reviewedAt: $request->reviewed_at?->toIso8601String(),
        );
    }
}
