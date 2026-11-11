<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
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
    public static function get(User $member, Company $company, ?string $roleKey, ?User $actor = null): array
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
                    'user_id' => $member->id,
                    'document_type_id' => $typeId,
                    'status' => DocumentRequest::STATUS_REQUESTED,
                    'requested_at' => now(),
                ]);
            }

            $doc['request_status'] = $request?->status;
            $doc['request_review_note'] = $request?->review_note;
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

        return ['documents' => $documents];
    }
}
