<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\ReadModels\SelfDocumentReadModel;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Modules\Core\Members\UseCases\DownloadOwnDocumentUseCase;
use App\Modules\Core\Members\UseCases\UploadOwnDocumentData;
use App\Modules\Core\Members\UseCases\UploadOwnDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-173: Self-document controller (passive).
 *
 * Maps HTTP to ReadModel/UseCases. Zero business logic.
 */
class SelfDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            SelfDocumentReadModel::get($request->user(), $company),
        );
    }

    public function upload(Request $request, string $code, UploadOwnDocumentUseCase $useCase, DocumentProcessingPipeline $pipeline): JsonResponse
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

        // ADR-409: Pipeline — orient, deskew, trim, merge PDF, OCR
        $processed = $pipeline->process($uploadedFiles, $code);

        try {
            // Passthrough = file couldn't be processed (fake in tests, encrypted, etc.)
            $fileForUseCase = $processed->passthrough
                ? $uploadedFiles[0]
                : new UploadedFile($processed->pdfPath, $processed->fileName, $processed->mimeType, null, true);

            $result = $useCase->execute(new UploadOwnDocumentData(
                user: $request->user(),
                company: $company,
                documentCode: $code,
                file: $fileForUseCase,
                expiresAt: $request->input('expires_at'),
            ));

            if ($processed->ocrText) {
                MemberDocument::where('id', $result->id)->update(['ocr_text' => $processed->ocrText]);
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

    public function download(Request $request, string $code, DownloadOwnDocumentUseCase $useCase)
    {
        $company = $request->attributes->get('company');

        $result = $useCase->execute($request->user(), $company, $code);

        return Storage::disk($result->disk)->download($result->filePath, $result->fileName);
    }

    /** ADR-760 (M3) : historique des versions de SES propres documents (self = autorisé). */
    public function versions(Request $request, string $code, DocumentVersioningService $versioning): JsonResponse
    {
        $company = $request->attributes->get('company');
        $type = DocumentType::where('code', $code)->firstOrFail();
        $document = MemberDocument::where('company_id', $company->id)
            ->where('document_subject_type', DocumentSubject::TYPE_USER)
            ->where('document_subject_id', $request->user()->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

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

    /** ADR-760 (M3) : téléchargement d'une version archivée de SES propres documents. */
    public function downloadVersion(Request $request, string $code, int $versionId)
    {
        $company = $request->attributes->get('company');
        $type = DocumentType::where('code', $code)->firstOrFail();
        $document = MemberDocument::where('company_id', $company->id)
            ->where('document_subject_type', DocumentSubject::TYPE_USER)
            ->where('document_subject_id', $request->user()->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        $version = DocumentVersion::where('documentable_type', 'member_document')
            ->where('documentable_id', $document->id)
            ->where('id', $versionId)
            ->firstOrFail();

        return Storage::disk('local')->download($version->file_path, $version->file_name);
    }
}
