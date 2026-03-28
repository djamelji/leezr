<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\RetryDocumentAiService;
use App\Jobs\Documents\ProcessDocumentAiJob;
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

class MemberDocumentController extends Controller
{
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
    public function upload(Request $request, int $membershipId, string $documentCode, DocumentProcessingPipeline $pipeline): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);
        $user = User::findOrFail($membership->user_id);

        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        // Verify activation exists for this company
        DocumentTypeActivation::where('company_id', $company->id)
            ->where('document_type_id', $type->id)
            ->where('enabled', true)
            ->firstOrFail();

        $rules = $type->validation_rules ?? [];
        $maxSize = ($rules['max_file_size_mb'] ?? 10) * 1024; // KB
        $acceptedTypes = $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];

        $expiresAtRule = $type->requires_expiration
            ? ['required', 'date', 'after:today']
            : ['nullable', 'date', 'after:today'];

        // ADR-409: Accept files[] (multi) or file (single, backward compat)
        $request->validate([
            'files' => ['required_without:file', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', "max:{$maxSize}", 'mimes:'.implode(',', $acceptedTypes)],
            'file' => ['required_without:files', 'file', "max:{$maxSize}", 'mimes:'.implode(',', $acceptedTypes)],
            'expires_at' => $expiresAtRule,
        ]);

        // ADR-169 Phase 4: Storage quota guard
        $quota = StorageQuotaService::usage($company);
        if ($quota['blocked']) {
            return response()->json([
                'message' => 'Storage quota exceeded. Upgrade your plan to upload more documents.',
                'storage' => $quota,
            ], 422);
        }

        $uploadedFiles = $request->hasFile('files')
            ? $request->file('files')
            : [$request->file('file')];

        $processed = $pipeline->process($uploadedFiles, $documentCode);

        try {
            if ($processed->passthrough) {
                $file = $uploadedFiles[0];
                $path = $file->store("documents/{$company->id}/{$user->id}", 'local');
                $fileName = $file->getClientOriginalName();
                $fileSize = $file->getSize();
                $mimeType = $file->getMimeType();
                $ocrText = null;
            } else {
                $path = Storage::disk('local')->putFileAs(
                    "documents/{$company->id}/{$user->id}",
                    new \Illuminate\Http\File($processed->pdfPath),
                    $processed->fileName,
                );
                $fileName = $processed->fileName;
                $fileSize = $processed->fileSize;
                $mimeType = $processed->mimeType;
                $ocrText = $processed->ocrText;
            }
        } finally {
            $pipeline->cleanup();
        }

        // Upsert (one document per type per user per company)
        $document = MemberDocument::updateOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'document_type_id' => $type->id,
            ],
            [
                'file_path' => $path,
                'file_name' => $fileName,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
                'uploaded_by' => $request->user()->id,
                'expires_at' => $request->input('expires_at'),
                'ocr_text' => $ocrText,
                'ai_status' => 'pending',
            ],
        );

        // ADR-413: Dispatch AI analysis job
        ProcessDocumentAiJob::dispatch(MemberDocument::class, $document->id, $type->id);

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

    public function retryAi(Request $request, int $membershipId, string $documentCode): JsonResponse
    {
        $company = $request->attributes->get('company');
        $document = MemberDocument::where('company_id', $company->id)
            ->where('user_id', User::findOrFail($membershipId)->id)
            ->where('document_type_id', DocumentType::where('code', $documentCode)->firstOrFail()->id)
            ->whereNotNull('file_path')
            ->firstOrFail();

        RetryDocumentAiService::retry($document);

        return response()->json(['message' => __('documents.retryAiSuccess'), 'ai_status' => 'pending']);
    }
}
