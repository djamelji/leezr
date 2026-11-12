<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-759 (DMS M2) — moteur du cycle de vie des demandes documentaires. Rattache une demande au
 * SUJET (user OU employee sans compte), applique les transitions AUTORISÉES uniquement, et
 * journalise CHAQUE transition dans `document_request_events` (append-only) → traçabilité complète,
 * motif de refus conservé, aucune preuve supprimée. Une acceptation enregistre la référence de la
 * version validée (sha256) ; un remplacement d'un document déjà accepté renvoie en soumission.
 */
class DocumentRequestService
{
    /**
     * Ouvre (ou ré-ouvre) une demande pour un sujet. Refuse si une demande est déjà ACTIVE.
     */
    public function open(int $companyId, DocumentSubject $subject, DocumentType $type, ?User $actor, ?string $message = null, ?string $dueDate = null, bool $requiresValidation = true): DocumentRequest
    {
        $existing = $this->findExisting($companyId, $subject, $type->id);
        $activeStates = [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_TO_REPLACE];

        if ($existing && in_array($existing->status, $activeStates, true)) {
            throw ValidationException::withMessages(['document_type_code' => ['A request for this document is already active.']]);
        }

        $request = $existing ?? new DocumentRequest([
            'company_id' => $companyId,
            'document_type_id' => $type->id,
        ]);
        $request->fill([
            'document_subject_type' => $subject->type,
            'document_subject_id' => $subject->id,
            'user_id' => $subject->is(DocumentSubject::TYPE_USER) ? $subject->id : null,
            'status' => DocumentRequest::STATUS_REQUESTED,
            'message' => $message,
            'due_date' => $dueDate,
            'requires_validation' => $requiresValidation,
            'approved_version_id' => null,
            'reviewer_id' => null,
            'review_note' => null,
            'reviewed_at' => null,
            'submitted_at' => null,
            'requested_at' => now(),
        ]);
        $request->save();

        $this->record($request, DocumentRequestEvent::E_REQUESTED, $actor, $message);

        return $request;
    }

    /**
     * Trouve la demande existante d'un sujet. Tolérant aux lignes legacy (sujet non renseigné)
     * pour un sujet user : match par sujet OU par user_id.
     */
    public function findExisting(int $companyId, DocumentSubject $subject, int $typeId): ?DocumentRequest
    {
        $query = DocumentRequest::where('company_id', $companyId)->where('document_type_id', $typeId);

        if ($subject->is(DocumentSubject::TYPE_USER)) {
            $query->where(function ($w) use ($subject) {
                $w->where(function ($a) use ($subject) {
                    $a->where('document_subject_type', DocumentSubject::TYPE_USER)->where('document_subject_id', $subject->id);
                })->orWhere('user_id', $subject->id);
            });
        } else {
            $query->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id);
        }

        return $query->first();
    }

    /**
     * Marque une soumission (dépôt de fichier). Crée la demande si absente (upload direct sans
     * demande préalable). Si la demande était ACCEPTÉE, un remplacement renvoie en soumission.
     */
    public function markSubmitted(int $companyId, DocumentSubject $subject, DocumentType $type, ?User $actor): DocumentRequest
    {
        $request = $this->findExisting($companyId, $subject, $type->id) ?? new DocumentRequest([
            'company_id' => $companyId,
            'document_type_id' => $type->id,
        ]);

        $existedBefore = $request->exists;
        $request->fill([
            'document_subject_type' => $subject->type,
            'document_subject_id' => $subject->id,
            'user_id' => $subject->is(DocumentSubject::TYPE_USER) ? $subject->id : null,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'reviewer_id' => null,
            'review_note' => null,
            'reviewed_at' => null,
        ]);
        if (! $existedBefore) {
            $request->requested_at = now();
            $request->requires_validation = true;
        }
        $request->save();

        $this->record($request, DocumentRequestEvent::E_SUBMITTED, $actor);

        return $request;
    }

    /** Passe en vérification (optionnel — un reviewer ouvre la pièce). */
    public function startReview(DocumentRequest $request, User $actor): DocumentRequest
    {
        if ($request->status === DocumentRequest::STATUS_SUBMITTED) {
            $request->update(['status' => DocumentRequest::STATUS_IN_REVIEW]);
            $this->record($request, DocumentRequestEvent::E_IN_REVIEW, $actor);
        }

        return $request;
    }

    /**
     * Accepte la pièce soumise : lie la référence de version validée (sha256 courant), fige le
     * reviewer/date. Transition autorisée depuis submitted/in_review.
     */
    public function approve(DocumentRequest $request, User $actor, ?MemberDocument $document): DocumentRequest
    {
        $this->assertTransition($request, DocumentRequest::STATUS_APPROVED);

        $request->update([
            'status' => DocumentRequest::STATUS_APPROVED,
            'reviewer_id' => $actor->id,
            'review_note' => null,
            'reviewed_at' => now(),
        ]);

        $this->record($request, DocumentRequestEvent::E_APPROVED, $actor, null, [
            'validated_sha256' => $document?->file_sha256,
            'validated_file_name' => $document?->file_name,
            'member_document_id' => $document?->id,
        ]);

        return $request;
    }

    /**
     * Refuse la pièce AVEC MOTIF. Le refus RESTE l'état courant (plus d'auto-re-request) : le motif
     * et l'historique sont conservés ; le collaborateur peut re-soumettre (→ submitted).
     */
    public function reject(DocumentRequest $request, User $actor, string $note): DocumentRequest
    {
        $this->assertTransition($request, DocumentRequest::STATUS_REJECTED);

        $request->update([
            'status' => DocumentRequest::STATUS_REJECTED,
            'reviewer_id' => $actor->id,
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);

        $this->record($request, DocumentRequestEvent::E_REJECTED, $actor, $note);

        return $request;
    }

    /** Demande le remplacement d'une pièce déjà acceptée (→ to_replace). */
    public function requestReplacement(DocumentRequest $request, User $actor, ?string $note = null): DocumentRequest
    {
        $this->assertTransition($request, DocumentRequest::STATUS_TO_REPLACE);
        $request->update(['status' => DocumentRequest::STATUS_TO_REPLACE]);
        $this->record($request, DocumentRequestEvent::E_TO_REPLACE, $actor, $note);

        return $request;
    }

    /** Annule la demande (conserve l'historique). */
    public function cancel(DocumentRequest $request, ?User $actor = null, ?string $note = null): DocumentRequest
    {
        $this->assertTransition($request, DocumentRequest::STATUS_CANCELLED);
        $request->update(['status' => DocumentRequest::STATUS_CANCELLED]);
        $this->record($request, DocumentRequestEvent::E_CANCELLED, $actor, $note);

        return $request;
    }

    private function assertTransition(DocumentRequest $request, string $target): void
    {
        if (! $request->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["Illegal transition {$request->status} → {$target}."],
            ]);
        }
    }

    private function record(DocumentRequest $request, string $event, ?User $actor, ?string $note = null, array $metadata = []): void
    {
        DocumentRequestEvent::create([
            'company_id' => $request->company_id,
            'document_request_id' => $request->id,
            'event' => $event,
            'actor_id' => $actor?->id,
            'note' => $note,
            'version_id' => $metadata['version_id'] ?? null,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
