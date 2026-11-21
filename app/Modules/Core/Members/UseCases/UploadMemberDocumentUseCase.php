<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentProcessingPipeline;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentComplianceStateService;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Core\Storage\StorageQuotaService;
use App\Jobs\Documents\ProcessDocumentAiJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-848 (Leezr 4.2 M9) — an administrator uploads a member's document (validated request : files[]|file,
 * expires_at). Moved verbatim from MemberDocumentController::upload : quota guard (ADR-169), processing pipeline,
 * unique storage path (ADR-770), integrity + non-destructive replacement + idempotence (ADR-756), AI analysis
 * (ADR-413), request marked submitted (ADR-759). The member's OWN upload is UploadOwnDocumentUseCase (different
 * quota rule — delta —, single file, transaction) : kept separate on purpose.
 */
class UploadMemberDocumentUseCase
{
    use PublishesRealtimeEvents;

    public function execute(Request $request, Company $company, User $user, DocumentType $type, string $documentCode, DocumentProcessingPipeline $pipeline): MemberDocument
    {
        // ADR-169 Phase 4: Storage quota guard
        $quota = StorageQuotaService::usage($company);
        if ($quota['blocked']) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Storage quota exceeded. Upgrade your plan to upload more documents.',
                'storage' => $quota,
            ], 422));
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
                // ADR-770 : chemin UNIQUE (putFile → nom aléatoire), jamais `putFileAs($processed->fileName)` :
                // le nom produit par le pipeline est déterministe (`{code}.pdf`) → un remplacement
                // ÉCRASAIT le fichier précédent, que la version archivée continuait de référencer.
                $path = Storage::disk('local')->putFile(
                    "documents/{$company->id}/{$user->id}",
                    new \Illuminate\Http\File($processed->pdfPath),
                );
                $fileName = $processed->fileName;
                $fileSize = $processed->fileSize;
                $mimeType = $processed->mimeType;
                $ocrText = $processed->ocrText;
            }
        } finally {
            $pipeline->cleanup();
        }

        // ADR-756 (DMS M0): intégrité + remplacement NON destructif + idempotence.
        $integrity = app(DocumentIntegrityService::class);
        $versioning = app(DocumentVersioningService::class);
        $newHash = $integrity->storedHash($path); // ADR-810.1 : échec de stockage → rien n'est écrit

        $keys = [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'document_type_id' => $type->id,
        ];
        $existing = MemberDocument::where($keys)->first();

        if ($existing && $versioning->isDuplicate($existing, $newHash)) {
            // Contenu identique → remplacement inutile : on jette le fichier fraîchement stocké,
            // on conserve le fichier courant et l'historique (idempotent).
            Storage::disk('local')->delete($path);
            $document = $existing;
        } else {
            // Archive la version précédente AVANT de pointer vers le nouveau fichier (non destructif :
            // le store() génère un nouveau chemin, l'ancien fichier reste référencé par la version).
            if ($existing && ! empty($existing->file_path)) {
                $versioning->snapshotCurrent($existing, DocumentVersion::SOURCE_UPLOAD, $request->user()->id);
            }

            $document = MemberDocument::updateOrCreate($keys, [
                'file_path' => $path,
                'file_name' => $fileName,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
                'uploaded_by' => $request->user()->id,
                'expires_at' => $request->input('expires_at'),
                'ocr_text' => $ocrText,
                'ai_status' => 'pending',
                'file_sha256' => $newHash,
                'published_state' => DocumentComplianceStateService::P_PUBLISHED,
                'document_subject_type' => DocumentSubject::TYPE_USER,
                'document_subject_id' => $user->id,
            ]);

            // ADR-413: Dispatch AI analysis job (seulement si le contenu a changé)
            ProcessDocumentAiJob::dispatch(MemberDocument::class, $document->id, $type->id);
        }

        // ADR-176 / ADR-759 (M2): soumission via le moteur de demandes (sujet-keyed + événement).
        app(\App\Core\Documents\Services\DocumentRequestService::class)
            ->markSubmitted($company->id, DocumentSubject::user($user->id), $type, $request->user());

        $this->publishDomainEvent('document.updated', $company->id, ['id' => $document->id, 'type' => 'uploaded', 'entity' => 'MemberDocument', 'member_id' => $user->id, 'document_type' => $type->code, 'ai_status' => 'pending']);


        return $document;
    }
}
