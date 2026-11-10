<?php

namespace App\Core\Documents\UseCases;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\Services\DocumentComplianceStateService;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Storage\StorageQuotaService;
use App\Jobs\Documents\ProcessDocumentAiJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-174: Company document upload use case.
 *
 * Orchestrates: membership → type → scope guard → activation → file validation
 * → quota delta → old file cleanup → store → upsert.
 */
class UploadCompanyDocumentUseCase
{
    public function execute(UploadCompanyDocumentData $data): UploadCompanyDocumentResult
    {
        // 1. Verify actor membership (defense in depth)
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load DocumentType by code
        $type = DocumentType::where('code', $data->documentCode)->firstOrFail();

        // 3. Scope guard: company vault only handles company scope
        if ($type->scope !== DocumentType::SCOPE_COMPANY) {
            throw ValidationException::withMessages([
                'document_code' => ['This document type is not available for company upload.'],
            ]);
        }

        // 4. Verify activation enabled for this company
        DocumentTypeActivation::where('company_id', $data->company->id)
            ->where('document_type_id', $type->id)
            ->where('enabled', true)
            ->firstOrFail();

        // 4b. ADR-406: Require expires_at when type demands it
        if ($type->requires_expiration && empty($data->expiresAt)) {
            throw ValidationException::withMessages([
                'expires_at' => ['An expiration date is required for this document type.'],
            ]);
        }

        // 5. File domain validation (from validation_rules)
        $rules = $type->validation_rules ?? [];
        $maxSizeMb = $rules['max_file_size_mb'] ?? 10;
        $acceptedTypes = $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];

        $fileSizeBytes = $data->file->getSize();
        if ($fileSizeBytes > $maxSizeMb * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ["The file must not be greater than {$maxSizeMb} MB."],
            ]);
        }

        // Validate MIME from file content (not extension)
        $mimeMap = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ];

        $allowedMimes = collect($acceptedTypes)
            ->flatMap(fn ($ext) => $mimeMap[strtolower($ext)] ?? [])
            ->unique()
            ->values()
            ->all();

        $detectedMime = $data->file->getMimeType();
        if (!in_array($detectedMime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => ['The file type is not accepted. Allowed: '.implode(', ', $acceptedTypes).'.'],
            ]);
        }

        // 6. Check existing document for delta calculation
        $existing = CompanyDocument::where('company_id', $data->company->id)
            ->where('document_type_id', $type->id)
            ->first();

        $existingSize = $existing?->file_size_bytes ?? 0;
        $delta = $fileSizeBytes - $existingSize;

        // 7. Quota delta check (convention: 0 = unlimited)
        $quotaCheck = StorageQuotaService::checkDelta($data->company, $delta);
        if (!$quotaCheck['allowed']) {
            throw new HttpException(422, 'Storage quota exceeded. Contact your administrator.');
        }

        // 8. ADR-757 (DMS M1) : remplacement NON DESTRUCTIF. Nouveau chemin (l'ancien fichier
        // n'est jamais supprimé, il reste pointé par la version). Hash → intégrité + idempotence.
        $path = $data->file->store("documents/{$data->company->id}/company", 'local');

        $integrity = app(DocumentIntegrityService::class);
        $versioning = app(DocumentVersioningService::class);
        $newHash = $integrity->hashOf($path);

        $isDuplicate = $existing && $existing->file_path && $versioning->isDuplicate($existing, $newHash);

        if ($isDuplicate) {
            Storage::disk('local')->delete($path);
            $document = $existing;
        } else {
            if ($existing && $existing->file_path) {
                $versioning->snapshotCurrent($existing, DocumentVersion::SOURCE_UPLOAD, $data->actor->id);
            }

            $document = CompanyDocument::updateOrCreate(
                [
                    'company_id' => $data->company->id,
                    'document_type_id' => $type->id,
                ],
                [
                    'file_path' => $path,
                    'file_name' => $data->file->getClientOriginalName(),
                    'file_size_bytes' => $fileSizeBytes,
                    'mime_type' => $data->file->getMimeType(),
                    'uploaded_by' => $data->actor->id,
                    'expires_at' => $data->expiresAt,
                    'ai_status' => 'pending',
                    'file_sha256' => $newHash,
                    'published_state' => DocumentComplianceStateService::P_PUBLISHED,
                ],
            );

            // ADR-411: Dispatch async AI analysis (inutile si contenu identique)
            ProcessDocumentAiJob::dispatch(CompanyDocument::class, $document->id, $type->id);
        }

        return new UploadCompanyDocumentResult(
            id: $document->id,
            code: $type->code,
            fileName: $document->file_name,
            fileSizeBytes: $document->file_size_bytes,
            uploadedAt: $document->updated_at->toIso8601String(),
            replaced: $existing !== null,
        );
    }
}
