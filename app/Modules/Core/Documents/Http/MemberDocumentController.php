<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Documents\RetryDocumentAiService;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Core\Documents\ReadModels\MemberDocumentWorkflowReadModel;
use App\Core\Models\User;
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
    use PublishesRealtimeEvents;
    public function index(Request $request, int $membershipId): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()
            ->with('companyRole:id,key')
            ->findOrFail($membershipId);

        $user = User::findOrFail($membership->user_id);
        $roleKey = $membership->companyRole?->key;

        return response()->json(
            MemberDocumentWorkflowReadModel::get($user, $company, $roleKey, $request->user()),
        );
    }

    public function upload(Request $request, int $membershipId, string $documentCode, DocumentProcessingPipeline $pipeline): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);
        \App\Core\Documents\Services\PersonDocumentContinuity::settleSubject($company->id, DocumentSubject::user((int) $membership->user_id)); // ADR-770
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

        $document = app(\App\Modules\Core\Members\UseCases\UploadMemberDocumentUseCase::class)
            ->execute($request, $company, $user, $type, $documentCode, $pipeline); // ADR-848

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

    public function download(Request $request, int $membershipId, string $documentCode, DocumentAccessPolicy $policy)
    {
        [$company, $type, $document, $subject] = $this->currentDocument($request, $membershipId, $documentCode); // ADR-848
        if (! $policy->canAccess($request->user(), $company, $subject, $type->code, DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'You are not authorized to download this document.');
        }

        $this->auditSensitiveAccess($request, $company->id, $type->code, 'member_document', (int) $document->id, (string) $subject);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    /**
     * ADR-757 (DMS M1): liste l'historique des versions conservées d'un document membre.
     */
    public function versions(Request $request, int $membershipId, string $documentCode, DocumentAccessPolicy $policy, DocumentVersioningService $versioning): JsonResponse
    {
        [$company, $type, $document, $subject] = $this->currentDocument($request, $membershipId, $documentCode); // ADR-848
        if (! $policy->canAccess($request->user(), $company, $subject, $type->code, DocumentAccessPolicy::OP_VERSIONS)) {
            abort(403, 'You are not authorized to view this document history.');
        }

        $versions = $versioning->history($document)->map(fn ($v) => [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'file_name' => $v->file_name,
            'file_size_bytes' => $v->file_size_bytes,
            'source' => $v->source,
            'archived_at' => $v->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['versions' => $versions]);
    }

    /**
     * ADR-757 (DMS M1): télécharge une version ARCHIVÉE — mêmes contrôles d'accès + confidentialité
     * que le document courant (protection des versions historiques).
     */
    public function downloadVersion(Request $request, int $membershipId, string $documentCode, int $versionId, DocumentAccessPolicy $policy)
    {
        [$company, $type, $document, $subject] = $this->currentDocument($request, $membershipId, $documentCode); // ADR-848
        if (! $policy->canAccess($request->user(), $company, $subject, $type->code, DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'You are not authorized to download this document version.');
        }

        $version = DocumentVersion::where('documentable_type', 'member_document')
            ->where('documentable_id', $document->id)
            ->where('id', $versionId)
            ->firstOrFail();

        $this->auditSensitiveAccess($request, $company->id, $type->code, 'document_version', (int) $version->id, (string) $subject);

        return Storage::disk('local')->download($version->file_path, $version->file_name);
    }

    /** ADR-848 : the member's CURRENT document of a type (subject settled first, ADR-770) — shared by download / versions. */
    private function currentDocument(Request $request, int $membershipId, string $documentCode): array
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);
        \App\Core\Documents\Services\PersonDocumentContinuity::settleSubject($company->id, DocumentSubject::user((int) $membership->user_id)); // ADR-770
        $type = DocumentType::where('code', $documentCode)->firstOrFail();
        $document = MemberDocument::where('company_id', $company->id)
            ->where('user_id', $membership->user_id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        return [$company, $type, $document, DocumentSubject::fromMemberDocument($document)];
    }

    /** Journalise l'accès aux pièces HAUTEMENT confidentielles (bulletins, ID, bancaire, casier). */
    private function auditSensitiveAccess(Request $request, int $companyId, string $typeCode, string $targetType, int $targetId, string $subject): void
    {
        if (! DocumentConfidentiality::isHighlyConfidential($typeCode)) {
            return;
        }

        app(AuditLogger::class)->logCompany($companyId, 'document.confidential_access', $targetType, (string) $targetId, [
            'severity' => 'notice',
            'metadata' => ['code' => $typeCode, 'subject' => $subject, 'actor_id' => $request->user()?->id],
        ]);
    }

    /**
     * ADR-176: Review (approve/reject) a member document request.
     */
    public function review(Request $request, int $membershipId, string $code, ReviewMemberDocumentUseCase $useCase): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'], 'extend_until' => ['nullable', 'date', 'after:today'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new ReviewMemberDocumentData(
            actor: $request->user(),
            company: $company,
            membershipId: $membershipId,
            documentCode: $code,
            status: $request->input('status'),
            reviewNote: $request->input('review_note'), extendUntil: $request->input('extend_until'),
        ));

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'reviewed', 'entity' => 'MemberDocument', 'document_type' => $code, 'status' => $result->status]);

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

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'deleted', 'entity' => 'MemberDocument', 'document_type' => $documentCode]);

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

        $this->publishDomainEvent('document.updated', $company->id, ['id' => $document->id, 'type' => 'ai.retried', 'entity' => 'MemberDocument', 'document_type' => $documentCode, 'ai_status' => 'pending']);

        return response()->json(['message' => __('documents.retryAiSuccess'), 'ai_status' => 'pending']);
    }
}
