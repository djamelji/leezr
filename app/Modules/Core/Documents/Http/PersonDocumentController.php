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
use App\Core\Documents\ReadModels\MemberDocumentWorkflowReadModel;
use App\Core\Documents\ReadModels\SubjectDocumentReadModel;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\DocumentComplianceStateService;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-758 (DMS M1.1) — dossier documentaire adressé par personRef (user-{id} | employee-{id}),
 * INDÉPENDANT d'un compte/membership. Résout le SUJET canonique : un compte User existe → sujet
 * `user:{id}` (coffre + workflow de validation role-aware) ; sinon Employee sans compte → sujet
 * `employee:{id}` (coffre par sujet, sans workflow). Aucun compte artificiel, aucun stockage dupliqué.
 */
class PersonDocumentController extends Controller
{
    public function index(Request $request, string $personRef): JsonResponse
    {
        $company = $request->attributes->get('company');
        [$subject, $user, $employee] = $this->resolve($company, $personRef);

        if ($user !== null) {
            $membership = $company->memberships()->where('user_id', $user->id)->with('companyRole:id,key')->first();
            $roleKey = $membership?->companyRole?->key;

            // Continuité : fusionne les documents déposés sous employee:{id} avant l'ajout du compte.
            $mergeEmployeeId = $employee?->id;

            return response()->json(
                MemberDocumentWorkflowReadModel::get($user, $company, $roleKey, $request->user(), $mergeEmployeeId),
            );
        }

        return response()->json(
            SubjectDocumentReadModel::get($company, $subject, $request->user()),
        );
    }

    public function upload(Request $request, string $personRef, string $documentCode, DocumentProcessingPipeline $pipeline): JsonResponse
    {
        $company = $request->attributes->get('company');
        [$subject, $user] = $this->resolve($company, $personRef);

        $type = DocumentType::where('code', $documentCode)->firstOrFail();
        if ($type->scope !== DocumentType::SCOPE_COMPANY_USER) {
            throw ValidationException::withMessages(['document_code' => ['This document type is not a personal document.']]);
        }
        DocumentTypeActivation::where('company_id', $company->id)
            ->where('document_type_id', $type->id)->where('enabled', true)->firstOrFail();

        $rules = $type->validation_rules ?? [];
        $maxSize = ($rules['max_file_size_mb'] ?? 10) * 1024;
        $accepted = $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
        $expiresRule = $type->requires_expiration ? ['required', 'date', 'after:today'] : ['nullable', 'date', 'after:today'];
        $request->validate([
            'file' => ['required', 'file', "max:{$maxSize}", 'mimes:'.implode(',', $accepted)],
            'expires_at' => $expiresRule,
        ]);

        $file = $request->file('file');
        $processed = $pipeline->process([$file], $documentCode);
        try {
            if ($processed->passthrough) {
                $path = $file->store("documents/{$company->id}/subject", 'local');
                $fileName = $file->getClientOriginalName();
                $fileSize = $file->getSize();
                $mimeType = $file->getMimeType();
                $ocrText = null;
            } else {
                // ADR-770 : chemin UNIQUE. Avec `putFileAs($processed->fileName)` tous les collaborateurs sans
                // compte d'une entreprise partageaient `documents/{company}/subject/{code}.pdf` : le dépôt
                // de l'un ÉCRASAIT la pièce de l'autre (et toute version précédente).
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
        $newHash = $integrity->hashOf($path);

        // Clé d'unicité par SUJET (supporte user_id null pour l'employé sans compte).
        $keys = [
            'company_id' => $company->id,
            'document_subject_type' => $subject->type,
            'document_subject_id' => $subject->id,
            'document_type_id' => $type->id,
        ];
        $existing = MemberDocument::where($keys)->first();

        if ($existing && $existing->file_path && $versioning->isDuplicate($existing, $newHash)) {
            Storage::disk('local')->delete($path);
            $document = $existing;
        } else {
            if ($existing && $existing->file_path) {
                $versioning->snapshotCurrent($existing, DocumentVersion::SOURCE_UPLOAD, $request->user()->id);
            }
            $document = MemberDocument::updateOrCreate($keys, [
                'user_id' => $user?->id,
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
            ]);
        }

        // ADR-759 (M2): soumission via le moteur de demandes (sujet-keyed, supporte l'employé sans
        // compte). Enregistre l'événement + relie la demande au dépôt.
        app(DocumentRequestService::class)->markSubmitted($company->id, $subject, $type, $request->user());

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => ['id' => $document->id, 'code' => $type->code, 'file_name' => $document->file_name],
        ]);
    }

    public function download(Request $request, string $personRef, string $documentCode, DocumentAccessPolicy $policy)
    {
        $company = $request->attributes->get('company');
        [$subject] = $this->resolve($company, $personRef);
        $type = DocumentType::where('code', $documentCode)->firstOrFail();
        $document = $this->findDocument($company, $subject, $type);

        if (! $policy->canAccess($request->user(), $company, $subject, $type->code, DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'You are not authorized to download this document.');
        }
        $this->audit($request, $company->id, $type->code, 'member_document', (int) $document->id, (string) $subject);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    public function versions(Request $request, string $personRef, string $documentCode, DocumentAccessPolicy $policy, DocumentVersioningService $versioning): JsonResponse
    {
        $company = $request->attributes->get('company');
        [$subject] = $this->resolve($company, $personRef);
        $type = DocumentType::where('code', $documentCode)->firstOrFail();
        $document = $this->findDocument($company, $subject, $type);

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

    public function downloadVersion(Request $request, string $personRef, string $documentCode, int $versionId, DocumentAccessPolicy $policy)
    {
        $company = $request->attributes->get('company');
        [$subject] = $this->resolve($company, $personRef);
        $type = DocumentType::where('code', $documentCode)->firstOrFail();
        $document = $this->findDocument($company, $subject, $type);

        if (! $policy->canAccess($request->user(), $company, $subject, $type->code, DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'You are not authorized to download this document version.');
        }

        $version = DocumentVersion::where('documentable_type', 'member_document')
            ->where('documentable_id', $document->id)->where('id', $versionId)->firstOrFail();

        $this->audit($request, $company->id, $type->code, 'document_version', (int) $version->id, (string) $subject);

        return Storage::disk('local')->download($version->file_path, $version->file_name);
    }

    /**
     * Résout un personRef en SUJET canonique + User/Employee, en vérifiant le tenant.
     * Invariant : un Employee garde le sujet `user:{id}` dès qu'un compte existe ; un Employee
     * SANS compte → sujet `employee:{id}` (aucun compte artificiel).
     *
     * @return array{0:DocumentSubject,1:?User,2:?Employee}
     */
    private function resolve($company, string $personRef): array
    {
        $ref = PersonRef::parse($personRef);
        if ($ref === null) {
            abort(422, 'Invalid person reference.');
        }

        $user = null;
        $employee = null;

        if ($ref->isUser()) {
            // Tenant : l'utilisateur doit être membre de la société.
            $company->memberships()->where('user_id', $ref->id)->firstOrFail();
            $user = User::findOrFail($ref->id);
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->first();
        } else {
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($ref->id);
            $user = $employee->user_id ? User::find($employee->user_id) : null;
        }

        // ADR-770 : transition de sujet AVANT toute lecture/écriture adressée par personRef.
        \App\Core\Documents\Services\PersonDocumentContinuity::settle($company, $user, $employee);

        $subject = $user !== null ? DocumentSubject::user($user->id) : DocumentSubject::employee($employee->id);

        return [$subject, $user, $employee];
    }

    private function findDocument($company, DocumentSubject $subject, DocumentType $type): MemberDocument
    {
        return MemberDocument::where('company_id', $company->id)
            ->where('document_subject_type', $subject->type)
            ->where('document_subject_id', $subject->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();
    }

    private function audit(Request $request, int $companyId, string $typeCode, string $targetType, int $targetId, string $subject): void
    {
        if (! DocumentConfidentiality::isHighlyConfidential($typeCode)) {
            return;
        }
        app(AuditLogger::class)->logCompany($companyId, 'document.confidential_access', $targetType, (string) $targetId, [
            'severity' => 'notice',
            'metadata' => ['code' => $typeCode, 'subject' => $subject, 'actor_id' => $request->user()?->id],
        ]);
    }
}
