<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\ReadModels\MemberDocumentWorkflowReadModel;
use App\Core\Models\User;
use App\Core\Storage\StorageQuotaService;
use App\Modules\Core\Members\UseCases\DeleteMemberDocumentData;
use App\Modules\Core\Members\UseCases\DeleteMemberDocumentUseCase;
use App\Modules\Core\Members\UseCases\ReviewMemberDocumentData;
use App\Modules\Core\Members\UseCases\ReviewMemberDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-169 Phase 3: Member document CRUD.
 * Routes scoped to company context via middleware.
 */
class MemberDocumentController extends Controller
{
    /**
     * List all document types (with upload + workflow status) for a member.
     * ADR-176: Uses MemberDocumentWorkflowReadModel for enriched workflow data.
     */
    public function index(Request $request, int $membershipId): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()
            ->with('companyRole:id,key')
            ->findOrFail($membershipId);

        $user = User::findOrFail($membership->user_id);
        $roleKey = $membership->companyRole?->key;

        return response()->json(
            MemberDocumentWorkflowReadModel::get($user, $company, $roleKey),
        );
    }

    /**
     * Upload a document for a member.
     */
    public function upload(Request $request, int $membershipId, string $documentCode): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);
        $user = User::findOrFail($membership->user_id);

        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        // Verify activation exists for this company
        $activation = DocumentTypeActivation::where('company_id', $company->id)
            ->where('document_type_id', $type->id)
            ->where('enabled', true)
            ->firstOrFail();

        $rules = $type->validation_rules ?? [];
        $maxSize = ($rules['max_file_size_mb'] ?? 10) * 1024; // KB
        $acceptedTypes = $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];

        $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxSize}",
                'mimes:'.implode(',', $acceptedTypes),
            ],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        // ADR-169 Phase 4: Storage quota guard
        $quota = StorageQuotaService::usage($company);
        if ($quota['blocked']) {
            return response()->json([
                'message' => 'Storage quota exceeded. Upgrade your plan to upload more documents.',
                'storage' => $quota,
            ], 422);
        }

        $file = $request->file('file');
        $path = $file->store("documents/{$company->id}/{$user->id}", 'local');

        // Upsert (one document per type per user per company)
        $document = MemberDocument::updateOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'document_type_id' => $type->id,
            ],
            [
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $request->user()->id,
                'expires_at' => $request->input('expires_at'),
            ],
        );

        // ADR-176: Update DocumentRequest workflow
        DocumentRequest::updateOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'document_type_id' => $type->id,
            ],
            [
                'status' => DocumentRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'requested_at' => now(),
                'reviewer_id' => null,
                'review_note' => null,
                'reviewed_at' => null,
            ],
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => [
                'id' => $document->id,
                'code' => $type->code,
                'file_name' => $document->file_name,
                'file_size_bytes' => $document->file_size_bytes,
                'uploaded_at' => $document->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Download a document.
     */
    public function download(Request $request, int $membershipId, string $documentCode)
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);

        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        $document = MemberDocument::where('company_id', $company->id)
            ->where('user_id', $membership->user_id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    /**
     * ADR-176: Review (approve/reject) a member document request.
     */
    public function review(Request $request, int $membershipId, string $code, ReviewMemberDocumentUseCase $useCase): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new ReviewMemberDocumentData(
            actor: $request->user(),
            company: $company,
            membershipId: $membershipId,
            documentCode: $code,
            status: $request->input('status'),
            reviewNote: $request->input('review_note'),
        ));

        return response()->json([
            'message' => 'Document review saved.',
            'review' => [
                'code' => $result->code,
                'status' => $result->status,
                'review_note' => $result->reviewNote,
                'reviewed_at' => $result->reviewedAt,
            ],
        ]);
    }

    /**
     * Delete a document (ADR-180: delegates to UseCase for workflow reset).
     */
    public function destroy(Request $request, int $membershipId, string $documentCode, DeleteMemberDocumentUseCase $useCase): JsonResponse
    {
        $company = $request->attributes->get('company');

        $useCase->execute(new DeleteMemberDocumentData(
            actor: $request->user(),
            company: $company,
            membershipId: $membershipId,
            documentCode: $documentCode,
        ));

        return response()->json([
            'message' => 'Document deleted.',
        ]);
    }
}
