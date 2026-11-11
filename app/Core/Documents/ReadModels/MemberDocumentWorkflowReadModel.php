<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\DocumentComplianceStateService as State;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-176 / ADR-757 (DMS M1): Enriches the admin member documents view with workflow data.
 *
 * Wraps DocumentResolverService output and adds request_status, review metadata, has_file,
 * can_review, the CONSOLIDATED compliance status, the document confidentiality and — gated on
 * the viewing ACTOR — can_download (an actor without the right never gets a download affordance
 * for a highly-confidential piece of someone else).
 */
class MemberDocumentWorkflowReadModel
{
    public static function get(User $member, Company $company, ?string $roleKey, ?User $actor = null, ?int $mergeEmployeeId = null): array
    {
        // 1. Resolve documents via existing service (surface effective du membre)
        $documents = DocumentResolverService::resolve(
            $member,
            $company->id,
            $roleKey,
            $company->market_key,
            scope: DocumentType::SCOPE_COMPANY_USER,
        );

        if (empty($documents)) {
            return ['documents' => []];
        }

        // 2. Map codes to type IDs for the request lookup
        $typeCodes = array_column($documents, 'code');
        $typesByCode = DocumentType::whereIn('code', $typeCodes)->get()->keyBy('code');

        $typeIds = $typesByCode->pluck('id')->toArray();

        // 3. Load existing DocumentRequests for this member
        $requests = DocumentRequest::where('company_id', $company->id)
            ->where('user_id', $member->id)
            ->whereIn('document_type_id', $typeIds)
            ->get()
            ->keyBy('document_type_id');

        // 3b. ADR-759 (M2) — CONTINUITÉ : documents déposés sous employee:{id} avant l'ajout du
        // compte. On les fusionne (lien EXPLICITE, aucun déplacement) quand la pièce user manque.
        $employeeUploads = collect();
        $employeeRequests = collect();
        if ($mergeEmployeeId !== null) {
            // Charge TOUTES les pièces du sujet employee (y compris les types hors surface de rôle)
            // → garantie « aucun document perdu ».
            $employeeUploads = MemberDocument::where('company_id', $company->id)
                ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)
                ->where('document_subject_id', $mergeEmployeeId)
                ->get()->keyBy('document_type_id');
            $employeeRequests = DocumentRequest::where('company_id', $company->id)
                ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)
                ->where('document_subject_id', $mergeEmployeeId)
                ->get()->keyBy('document_type_id');
        }

        $policy = app(DocumentAccessPolicy::class);

        // 4. Enrich each document with workflow data
        foreach ($documents as &$doc) {
            $type = $typesByCode->get($doc['code']);
            $typeId = $type?->id;
            $request = $typeId ? $requests->get($typeId) : null;

            // ADR-389: Lazy-create for required docs (mandatory OR required_override)
            // CONSTRAINT: only for docs already in resolver output (effective surface)
            if ($doc['required'] && $request === null && $typeId) {
                $request = DocumentRequest::create([
                    'company_id' => $company->id,
                    'document_subject_type' => DocumentSubject::TYPE_USER,
                    'document_subject_id' => $member->id,
                    'user_id' => $member->id,
                    'document_type_id' => $typeId,
                    'status' => DocumentRequest::STATUS_REQUESTED,
                    'requested_at' => now(),
                ]);
            }

            // CONTINUITÉ : si aucune pièce user, récupérer celle déposée sous employee:{id}.
            if ($doc['upload'] === null && $typeId && ($empUpload = $employeeUploads->get($typeId))) {
                $doc['upload'] = [
                    'id' => $empUpload->id,
                    'file_name' => $empUpload->file_name,
                    'file_size_bytes' => $empUpload->file_size_bytes,
                    'mime_type' => $empUpload->mime_type,
                    'expires_at' => $empUpload->expires_at?->toIso8601String(),
                    'uploaded_at' => $empUpload->created_at?->toIso8601String(),
                ];
                $doc['lifecycle_status'] = \App\Core\Documents\DocumentLifecycleService::computeStatus(['expires_at' => $empUpload->expires_at]);
                $request = $request ?? ($typeId ? $employeeRequests->get($typeId) : null);
            }

            $doc['request_status'] = $request?->status;
            $doc['request_review_note'] = $request?->review_note;
            $doc['request_message'] = $request?->message;
            $doc['due_date'] = $request?->due_date?->toDateString();
            $doc['is_overdue'] = (bool) $request?->isOverdue();
            $doc['requested_at'] = $request?->requested_at?->toIso8601String();
            $doc['submitted_at'] = $request?->submitted_at?->toIso8601String();
            $doc['reviewed_at'] = $request?->reviewed_at?->toIso8601String();
            $doc['has_file'] = $doc['upload'] !== null;
            $doc['can_review'] = $request?->status === DocumentRequest::STATUS_SUBMITTED;

            // ADR-757/758 (DMS M1/M1.1): état consolidé + confidentialité + accès effectif + redaction.
            $state = State::evaluateFromParts($doc['has_file'], $request?->status, $doc['lifecycle_status'] ?? 'missing');
            $doc['compliance_status'] = $state['consolidated'];
            $doc['satisfies_obligation'] = $state['satisfies_obligation'];
            $doc['confidentiality'] = DocumentConfidentiality::forTypeCode($doc['code']);
            $doc['is_highly_confidential'] = $doc['confidentiality'] === DocumentConfidentiality::HIGHLY_CONFIDENTIAL;

            $subject = DocumentSubject::user($member->id);
            $canView = $actor !== null
                && $policy->canAccess($actor, $company, $subject, $doc['code'], DocumentAccessPolicy::OP_VIEW_METADATA);
            $doc['can_download'] = $doc['has_file'] && $canView;

            // Redaction : un acteur non autorisé ne découvre ni l'existence du fichier ni ses
            // métadonnées sensibles (bulletin/ID/bancaire d'autrui).
            if (! $canView) {
                $doc = \App\Core\Documents\ReadModels\SubjectDocumentReadModel::redact($doc);
                $doc['request_status'] = $request?->status; // statut de workflow conservé (non sensible)
                $doc['can_review'] = false;
            }
        }

        unset($doc);

        // 5. CONTINUITÉ (garantie « aucun document perdu ») : documents déposés sous employee:{id}
        // pour un type ABSENT de la surface de rôle du compte → ajoutés en fin de dossier.
        if ($employeeUploads->isNotEmpty()) {
            $renderedTypeIds = $typesByCode->only(array_column($documents, 'code'))->pluck('id')->all();
            $extraTypeIds = $employeeUploads->keys()->diff($renderedTypeIds);
            if ($extraTypeIds->isNotEmpty()) {
                $extraTypes = DocumentType::whereIn('id', $extraTypeIds)->get()->keyBy('id');
                foreach ($extraTypeIds as $tid) {
                    $type = $extraTypes->get($tid);
                    if (! $type) {
                        continue;
                    }
                    $upload = $employeeUploads->get($tid);
                    $request = $employeeRequests->get($tid);
                    $documents[] = self::carryOverDoc($company, $type, $upload, $request, $actor, $policy);
                }
            }
        }

        return ['documents' => $documents];
    }

    /** Entrée de dossier pour un document hérité du sujet employee (hors surface de rôle). */
    private static function carryOverDoc(Company $company, DocumentType $type, MemberDocument $upload, ?DocumentRequest $request, ?User $actor, $policy): array
    {
        $lifecycle = \App\Core\Documents\DocumentLifecycleService::computeStatus(['expires_at' => $upload->expires_at]);
        $hasFile = ! empty($upload->file_path);
        $state = State::evaluateFromParts($hasFile, $request?->status, $lifecycle);
        $rules = $type->validation_rules ?? [];

        $doc = [
            'code' => $type->code,
            'label' => $type->label,
            'scope' => $type->scope,
            'required' => false,
            'mandatory' => false,
            'requires_expiration' => (bool) $type->requires_expiration,
            'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'png'],
            'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
            'lifecycle_status' => $lifecycle,
            'upload' => [
                'id' => $upload->id,
                'file_name' => $upload->file_name,
                'file_size_bytes' => $upload->file_size_bytes,
                'mime_type' => $upload->mime_type,
                'expires_at' => $upload->expires_at?->toIso8601String(),
                'uploaded_at' => $upload->created_at?->toIso8601String(),
            ],
            'request_status' => $request?->status,
            'request_review_note' => $request?->review_note,
            'request_message' => $request?->message,
            'due_date' => $request?->due_date?->toDateString(),
            'is_overdue' => (bool) $request?->isOverdue(),
            'has_file' => $hasFile,
            'can_review' => false,
            'carried_over' => true,
            'compliance_status' => $state['consolidated'],
            'satisfies_obligation' => $state['satisfies_obligation'],
            'confidentiality' => DocumentConfidentiality::forTypeCode($type->code),
            'is_highly_confidential' => DocumentConfidentiality::forTypeCode($type->code) === DocumentConfidentiality::HIGHLY_CONFIDENTIAL,
        ];

        $subject = DocumentSubject::employee((int) $upload->document_subject_id);
        $canView = $actor !== null && $policy->canAccess($actor, $company, $subject, $type->code, DocumentAccessPolicy::OP_VIEW_METADATA);
        $doc['can_download'] = $hasFile && $canView;
        if (! $canView) {
            $doc = \App\Core\Documents\ReadModels\SubjectDocumentReadModel::redact($doc);
        }

        return $doc;
    }
}
