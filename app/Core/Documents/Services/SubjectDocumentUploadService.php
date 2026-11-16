<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-796 — the SINGLE deposit path of a personal document for a subject (extracted, unchanged, from the
 * person file, ADR-758/759/770/777) : processing pipeline, private storage under a unique path, duplicate
 * detection by hash, versioning, then the DMS request engine (`markSubmitted` : ONE request per subject and
 * type, `submitted`, validation required — never validated by the deposit). Used by the HR person file and
 * by the onboarding portal (uploader = null : the candidate has no account).
 */
final class SubjectDocumentUploadService
{
    /** Validation rules of a type, identical for every caller. */
    public static function fileRules(DocumentType $type): array
    {
        $rules = $type->validation_rules ?? [];
        $maxSize = ($rules['max_file_size_mb'] ?? 10) * 1024;
        $accepted = $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];

        return ['file', "max:{$maxSize}", 'mimes:'.implode(',', $accepted)];
    }

    /** @param list<UploadedFile> $files */
    public function store(Company $company, DocumentSubject $subject, ?User $subjectUser, DocumentType $type, array $files, ?string $expiresAt, ?int $uploadedBy, ?User $actor): MemberDocument
    {
        $pipeline = app(DocumentProcessingPipeline::class);
        $file = $files[0];
        $processed = $pipeline->process($files, $type->code);
        try {
            if ($processed->passthrough) {
                $path = $file->store("documents/{$company->id}/subject", 'local');
                $fileName = $file->getClientOriginalName();
                $fileSize = $file->getSize();
                $mimeType = $file->getMimeType();
                $ocrText = null;
            } else {
                // ADR-770 : chemin UNIQUE (jamais un nom partagé entre sujets).
                $path = Storage::disk('local')->putFile("documents/{$company->id}/subject", new \Illuminate\Http\File($processed->pdfPath));
                $fileName = $processed->fileName;
                $fileSize = $processed->fileSize;
                $mimeType = $processed->mimeType;
                $ocrText = $processed->ocrText;
            }
        } finally {
            $pipeline->cleanup();
        }

        $integrity = app(DocumentIntegrityService::class);
        $versioning = app(DocumentVersioningService::class);
        $newHash = $integrity->storedHash($path); // ADR-810.1 : échec de stockage → rien n'est écrit
        $keys = ['company_id' => $company->id, 'document_subject_type' => $subject->type, 'document_subject_id' => $subject->id, 'document_type_id' => $type->id];
        $existing = MemberDocument::where($keys)->first();

        if ($existing && $existing->file_path && $versioning->isDuplicate($existing, $newHash)) {
            Storage::disk('local')->delete($path);
            $document = $existing;
        } else {
            if ($existing && $existing->file_path) {
                $versioning->snapshotCurrent($existing, DocumentVersion::SOURCE_UPLOAD, $uploadedBy);
            }
            $document = MemberDocument::updateOrCreate($keys, [
                'user_id' => $subjectUser?->id, 'file_path' => $path, 'file_name' => $fileName, 'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType, 'uploaded_by' => $uploadedBy, 'expires_at' => $expiresAt, 'ocr_text' => $ocrText,
                'ai_status' => 'pending', 'file_sha256' => $newHash, 'published_state' => DocumentComplianceStateService::P_PUBLISHED,
            ]);
        }

        // ADR-759 : soumission via le moteur de demandes (une seule demande par sujet et type).
        app(DocumentRequestService::class)->markSubmitted($company->id, $subject, $type, $actor);

        return $document;
    }
}
