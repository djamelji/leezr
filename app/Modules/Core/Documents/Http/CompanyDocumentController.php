<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\ReadModels\CompanyDocumentReadModel;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Documents\UseCases\DownloadCompanyDocumentUseCase;
use App\Core\Documents\UseCases\UploadCompanyDocumentData;
use App\Core\Documents\UseCases\UploadCompanyDocumentUseCase;
use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-174: Company document vault controller (passive).
 *
 * Maps HTTP to ReadModel/UseCases. Zero business logic.
 */
class CompanyDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            CompanyDocumentReadModel::get($company),
        );
    }

    public function upload(Request $request, string $code, UploadCompanyDocumentUseCase $useCase, DocumentProcessingPipeline $pipeline): JsonResponse
    {
        $request->validate([
            'files' => ['required_without:file', 'array', 'min:1', 'max:10'],
            'files.*' => ['file'],
            'file' => ['required_without:files', 'file'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $company = $request->attributes->get('company');

        // ADR-409: Accept files[] (multi) or file (single, backward compat)
        $uploadedFiles = $request->hasFile('files')
            ? $request->file('files')
            : [$request->file('file')];

        $processed = $pipeline->process($uploadedFiles, $code);

        try {
            $fileForUseCase = $processed->passthrough
                ? $uploadedFiles[0]
                : new UploadedFile($processed->pdfPath, $processed->fileName, $processed->mimeType, null, true);

            $result = $useCase->execute(new UploadCompanyDocumentData(
                actor: $request->user(),
                company: $company,
                documentCode: $code,
                file: $fileForUseCase,
                expiresAt: $request->input('expires_at'),
            ));

            if ($processed->ocrText) {
                CompanyDocument::where('id', $result->id)->update(['ocr_text' => $processed->ocrText]);
            }
        } finally {
            $pipeline->cleanup();
        }

        return response()->json([
            'message' => $result->replaced ? 'Document replaced.' : 'Document uploaded.',
            'document' => [
                'id' => $result->id,
                'code' => $result->code,
                'file_name' => $result->fileName,
                'file_size_bytes' => $result->fileSizeBytes,
                'uploaded_at' => $result->uploadedAt,
            ],
        ]);
    }

    public function download(Request $request, string $code, DownloadCompanyDocumentUseCase $useCase)
    {
        $company = $request->attributes->get('company');

        $result = $useCase->execute($request->user(), $company, $code);

        return Storage::disk($result->disk)->download($result->filePath, $result->fileName);
    }

    /**
     * ADR-758 (DMS M1.1): historique des versions Company (auteur, date, hash, version courante).
     * N'expose PAS les chemins de stockage. Isolation tenant par le scope company.
     */
    public function versions(Request $request, string $code, DocumentAccessPolicy $policy, DocumentVersioningService $versioning): JsonResponse
    {
        $company = $request->attributes->get('company');
        $type = DocumentType::where('code', $code)->firstOrFail();
        $document = CompanyDocument::where('company_id', $company->id)
            ->where('document_type_id', $type->id)->firstOrFail();

        if (! $policy->canAccess($request->user(), $company, DocumentSubject::company((int) $company->id), $type->code, DocumentAccessPolicy::OP_VERSIONS)) {
            abort(403, 'You are not authorized to view this document history.');
        }

        $archived = $versioning->history($document);
        $authorIds = $archived->pluck('uploaded_by')->filter()->unique()->all();
        $authors = User::whereIn('id', $authorIds)->pluck('name', 'id');

        $versions = $archived->map(fn ($v) => [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'file_name' => $v->file_name,
            'file_size_bytes' => $v->file_size_bytes,
            'file_sha256' => $v->file_sha256,
            'author' => $v->uploaded_by ? ($authors[$v->uploaded_by] ?? null) : null,
            'archived_at' => $v->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'current' => [
                'file_name' => $document->file_name,
                'file_size_bytes' => $document->file_size_bytes,
                'file_sha256' => $document->file_sha256,
                'uploaded_at' => $document->updated_at?->toIso8601String(),
            ],
            'versions' => $versions,
        ]);
    }

    /** ADR-758 (DMS M1.1): télécharge une version Company archivée (gated, tenant-scopé). */
    public function downloadVersion(Request $request, string $code, int $versionId, DocumentAccessPolicy $policy)
    {
        $company = $request->attributes->get('company');
        $type = DocumentType::where('code', $code)->firstOrFail();
        $document = CompanyDocument::where('company_id', $company->id)
            ->where('document_type_id', $type->id)->firstOrFail();

        if (! $policy->canAccess($request->user(), $company, DocumentSubject::company((int) $company->id), $type->code, DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'You are not authorized to download this document version.');
        }

        $version = DocumentVersion::where('documentable_type', 'company_document')
            ->where('documentable_id', $document->id)
            ->where('company_id', $company->id)
            ->where('id', $versionId)->firstOrFail();

        return Storage::disk('local')->download($version->file_path, $version->file_name);
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        $company = $request->attributes->get('company');
        $type = DocumentType::where('code', $code)->firstOrFail();

        $document = CompanyDocument::where('company_id', $company->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        // Delete physical file
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }
}
