<?php

namespace App\Modules\Core\Documents\Http;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\ReadModels\DocumentRequestQueueReadModel;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Modules\Core\Members\UseCases\BatchRequestByRoleUseCase;
use App\Modules\Core\Members\UseCases\CancelDocumentRequestUseCase;
use App\Modules\Core\Members\UseCases\RequestDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-192: Document request endpoints (single + batch + queue).
 *
 * Controller is passive — delegates writes to UseCases, reads to ReadModel.
 */
class DocumentRequestController
{
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
}
