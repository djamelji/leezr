<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\Services\PersonDocumentContinuity;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Modules\Core\Members\UseCases\ReviewMemberDocumentUseCase;
use App\Modules\Core\People\PersonRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-772 — review (approve / reject) of a person's document addressed by `personRef` : works for
 * a member AND for an HR record without account. Same use case as the member route — the subject is
 * resolved here, the validation logic lives in one place.
 */
class PersonDocumentReviewController
{
    use PublishesRealtimeEvents;

    public function review(Request $request, string $personRef, string $code, ReviewMemberDocumentUseCase $useCase): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'extend_until' => ['nullable', 'date', 'after:today'], // ADR-773 : prolongation explicite
        ]);

        $company = $request->attributes->get('company');
        $ref = PersonRef::parse($personRef);
        abort_if($ref === null, 422, 'Invalid person reference.');

        // Tenant-scoped resolution (404 outside the company) ; rows follow the person first (ADR-770).
        $ids = PersonSubjectResolver::identify($company, $ref->type, $ref->id);
        PersonDocumentContinuity::settle($company, $ids['user'], $ids['employee']);
        $subject = PersonSubjectResolver::resolve($company, $ids['user'], $ids['employee'])['canonical'];
        abort_if($subject === null, 422, 'Unresolvable subject.');

        $result = $useCase->reviewSubject($request->user(), $company, $subject, $code, $data['status'], $data['review_note'] ?? null, $data['status'] === 'approved' ? ($data['extend_until'] ?? null) : null);

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'reviewed', 'entity' => 'MemberDocument', 'document_type' => $code, 'status' => $result->status]);

        return response()->json([
            'message' => 'Document review saved.',
            'review' => ['code' => $result->code, 'status' => $result->status, 'review_note' => $result->reviewNote, 'reviewed_at' => $result->reviewedAt],
        ]);
    }
}
