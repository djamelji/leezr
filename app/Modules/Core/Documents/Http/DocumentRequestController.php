<?php

namespace App\Modules\Core\Documents\Http;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\ReadModels\DocumentRequestQueueReadModel;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Modules\Core\Members\UseCases\BatchRequestByRoleUseCase;
use App\Modules\Core\Members\UseCases\CancelDocumentRequestUseCase;
use App\Modules\Core\Members\UseCases\RequestDocumentUseCase;
use App\Modules\Core\Members\UseCases\ReviewMemberDocumentData;
use App\Modules\Core\Members\UseCases\ReviewMemberDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-192: Document request endpoints (single + batch + queue).
 *
 * Controller is passive — delegates writes to UseCases, reads to ReadModel.
 */
class DocumentRequestController
{
    use PublishesRealtimeEvents;
    public function store(Request $request, RequestDocumentUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'document_type_code' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        $docRequest = $useCase->execute(
            $company->id,
            $validated['user_id'],
            $validated['document_type_code'],
        );

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'requested', 'entity' => 'DocumentRequest', 'document_type' => $validated['document_type_code'], 'user_id' => $validated['user_id']]);

        return response()->json([
            'message' => 'Document requested.',
            'document_request' => $docRequest,
        ], 201);
    }

    public function batchByRole(Request $request, BatchRequestByRoleUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:all,role,member'],
            'company_role_ids' => ['required_if:scope,role', 'array', 'nullable'],
            'company_role_ids.*' => ['integer'],
            'user_ids' => ['required_if:scope,member', 'array', 'nullable'],
            'user_ids.*' => ['integer'],
            'document_type_code' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        // --- Scope: member — batch via individual requests ---
        if ($validated['scope'] === 'member') {
            $userIds = $validated['user_ids'] ?? [];
            $singleUseCase = app(RequestDocumentUseCase::class);
            $created = 0;
            $skipped = 0;

            foreach ($userIds as $userId) {
                $membership = Membership::where('company_id', $company->id)
                    ->where('user_id', $userId)
                    ->first();

                if (! $membership) {
                    $skipped++;

                    continue;
                }

                try {
                    $singleUseCase->execute(
                        $company->id,
                        $membership->user_id,
                        $validated['document_type_code'],
                    );
                    $created++;
                } catch (\Throwable) {
                    $skipped++;
                }
            }

            return response()->json([
                'message' => "Batch complete: {$created} created, {$skipped} skipped.",
                'created' => $created,
                'skipped' => $skipped,
            ]);
        }

        // --- Scope: role (multiple roles) or all (role=null) ---
        $companyRoleIds = $validated['scope'] === 'role'
            ? ($validated['company_role_ids'] ?? [])
            : null;

        $result = $useCase->execute(
            $company->id,
            $companyRoleIds,
            $validated['document_type_code'],
        );

        return response()->json([
            'message' => "Batch complete: {$result['created']} created, {$result['skipped']} skipped.",
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'queue' => DocumentRequestQueueReadModel::forCompany($company->id),
        ]);
    }

    public function cancel(Request $request, int $requestId, CancelDocumentRequestUseCase $useCase): JsonResponse
    {
        $company = $request->attributes->get('company');
        $useCase->execute($company, $requestId, $request->user());

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'cancelled', 'entity' => 'DocumentRequest']);

        return response()->json(['message' => 'Document request cancelled.']);
    }

    /**
     * ADR-406: Lightweight roles listing for batch request dialog.
     * Protected by documents.manage (not roles.view).
     */
    public function batchRoles(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $roles = CompanyRole::where('company_id', $company->id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json(['roles' => $roles]);
    }

    public function remind(Request $request, int $requestId): JsonResponse
    {
        $company = $request->attributes->get('company');

        $docRequest = DocumentRequest::where('company_id', $company->id)
            ->where('id', $requestId)
            ->firstOrFail();

        if ($docRequest->status !== DocumentRequest::STATUS_REQUESTED) {
            abort(422, 'Only requests in "requested" status can be reminded.');
        }

        $docRequest->update(['requested_at' => now()]);

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

        return response()->json(['message' => 'Reminder sent.']);
    }

    /**
     * ADR-423: Bulk approve/reject submitted document requests.
     * Loops through IDs and delegates to ReviewMemberDocumentUseCase.
     */
    public function bulkAction(Request $request, ReviewMemberDocumentUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->attributes->get('company');
        $actor = $request->user();

        $docRequests = DocumentRequest::where('company_id', $company->id)
            ->whereIn('id', $validated['ids'])
            ->where('status', DocumentRequest::STATUS_SUBMITTED)
            ->with(['user', 'documentType'])
            ->get();

        $processed = 0;
        $skipped = 0;

        foreach ($docRequests as $docRequest) {
            $membership = Membership::where('company_id', $company->id)
                ->where('user_id', $docRequest->user_id)
                ->first();

            if (! $membership) {
                $skipped++;

                continue;
            }

            try {
                $useCase->execute(new ReviewMemberDocumentData(
                    actor: $actor,
                    company: $company,
                    membershipId: $membership->id,
                    documentCode: $docRequest->documentType->code,
                    status: $validated['action'],
                    reviewNote: $validated['review_note'] ?? null,
                ));
                $processed++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        if ($processed > 0) {
            $this->publishDomainEvent('document.updated', $company->id, ['type' => 'bulk_reviewed', 'entity' => 'DocumentRequest', 'action' => $validated['action'], 'count' => $processed]);
        }

        return response()->json([
            'message' => "Bulk action complete: {$processed} processed, {$skipped} skipped.",
            'processed' => $processed,
            'skipped' => $skipped,
        ]);
    }
}
