<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Models\Company;
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


        return $this->reviewSubject($data->actor, $data->company, DocumentSubject::user($targetUser->id), $data->documentCode, $data->status, $data->reviewNote, $data->status === 'approved' ? $data->extendUntil : null);
    }

    /**
     * ADR-772 — review of the document of a SUBJECT : a member (`user`) or an HR record WITHOUT
     * account (`employee`). One validation logic for both (same engine, same transitions, same
     * events) ; an account-less employee is reviewed as such — no account is created or required.
     */
    public function reviewSubject(User $actor, Company $company, DocumentSubject $subject, string $documentCode, string $status, ?string $reviewNote, ?string $extendUntil = null): ReviewMemberDocumentResult
    {
        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        // Scope guard : the type must match the subject (a person's document, or a company document).
        $expectedScope = $subject->is(DocumentSubject::TYPE_COMPANY) ? DocumentType::SCOPE_COMPANY : DocumentType::SCOPE_COMPANY_USER;
        if ($type->scope !== $expectedScope) {
            throw ValidationException::withMessages([
                'document_code' => ['Review is not available for this document type.'],
            ]);
        }

        $request = $this->requests->findExisting($company->id, $subject, $type->id);
        abort_if($request === null, 404);

        if ($status === DocumentRequest::STATUS_APPROVED && $subject->is(DocumentSubject::TYPE_COMPANY)) {
            // ADR-772 : obligation portée par l'entreprise → le fichier est celui de SON coffre.
            $companyDocument = \App\Core\Documents\CompanyDocument::where('company_id', $company->id)->where('document_type_id', $type->id)->first();
            if ($companyDocument === null || empty($companyDocument->file_path)) {
                throw ValidationException::withMessages(['status' => ['Cannot approve: no file has been submitted.']]);
            }
            $this->requests->approve($request, $actor, $companyDocument, $this->renewalProof($request, $companyDocument, $actor, $extendUntil));
        } elseif ($status === DocumentRequest::STATUS_APPROVED) {
            // Tolérant aux documents legacy d'un membre (sujet non renseigné) : match sujet OU user_id.
            $document = MemberDocument::where('company_id', $company->id)
                ->where('document_type_id', $type->id)
                ->where(function ($w) use ($subject) {
                    $w->where(fn ($a) => $a->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id));
                    if ($subject->is(DocumentSubject::TYPE_USER)) {
                        $w->orWhere('user_id', $subject->id);
                    }
                })
                ->first();

            if ($document === null || empty($document->file_path)) {
                throw ValidationException::withMessages(['status' => ['Cannot approve: no file has been submitted.']]);
            }

            $this->requests->approve($request, $actor, $document, $this->renewalProof($request, $document, $actor, $extendUntil));
        } else {
            $this->requests->reject($request, $actor, (string) $reviewNote);
        }

        // ADR-389: notify the PERSON about the outcome — only when she has an account to be notified on.
        $targetUser = $subject->is(DocumentSubject::TYPE_USER) ? User::find($subject->id) : null;
        if ($targetUser !== null) {
            NotificationDispatcher::send(
                topicKey: 'documents.reviewed',
                recipients: [$targetUser],
                payload: [
                    'document_type' => $type->label,
                    'document_code' => $type->code,
                    'status' => $status,
                    'review_note' => $reviewNote,
                    'link' => '/account-settings/documents',
                ],
                company: $company,
                entityKey: "document_request:{$targetUser->id}:{$type->code}",
            );
        }

        $request->refresh();

        return new ReviewMemberDocumentResult(
            code: $type->code,
            status: $request->status,
            reviewNote: $request->review_note,
            reviewedAt: $request->reviewed_at?->toIso8601String(),
        );
    }

    /**
     * ADR-773 — an approval bears on an IDENTIFIABLE version. An expired document, or a pending
     * renewal whose file is still the one that was expiring, is NOT made compliant by approving the
     * request : it needs a new version, or an explicit, audited decision to extend the same document
     * (`extend_until`). Returns the proof recorded on the `approved` event.
     */
    private function renewalProof(DocumentRequest $request, \Illuminate\Database\Eloquent\Model $document, User $actor, ?string $extendUntil): array
    {
        if ($extendUntil !== null) {
            $previous = $document->expires_at?->toDateString();
            $document->update(['expires_at' => $extendUntil]);
            app(\App\Core\Audit\AuditLogger::class)->logCompany($request->company_id, 'document.expiry_extended', 'document_request', (string) $request->id, [
                'actorId' => $actor->id, 'actorType' => 'user', 'severity' => 'warning',
                'metadata' => ['document_id' => $document->id, 'document_class' => class_basename($document), 'previous_expires_at' => $previous, 'extended_until' => $extendUntil, 'file_sha256' => $document->file_sha256],
            ]);

            return ['proof' => 'extension', 'previous_expires_at' => $previous, 'extended_until' => $extendUntil];
        }

        if ($document->expires_at !== null && $document->expires_at->isPast()) {
            throw ValidationException::withMessages(['status' => ['Cannot approve: the document has expired. Submit a new version or record an explicit extension.']]);
        }

        $renewal = $this->requests->pendingRenewal($request);
        if ($renewal === null) {
            return [];
        }
        $submitted = in_array($request->status, [DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW], true);
        $sameFile = $renewal['file_sha256'] !== null && $document->file_sha256 !== null
            ? hash_equals($renewal['file_sha256'], $document->file_sha256)
            : false;
        if (! $submitted || $sameFile) {
            throw ValidationException::withMessages(['status' => ['Cannot approve a renewal without a new version of the document. Submit a new file or record an explicit extension.']]);
        }

        return ['proof' => 'new_version', 'renewed_from_sha256' => $renewal['file_sha256']];
    }
}
