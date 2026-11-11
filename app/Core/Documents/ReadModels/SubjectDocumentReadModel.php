<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\DocumentComplianceStateService as State;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-758 (DMS M1.1) — liste documentaire par SUJET explicite (user OU employee), indépendante
 * d'un compte/membership. Permet le dossier d'un Employee SANS User. Les uploads sont retrouvés
 * via `document_subject_type/id` (pas via user_id). Chaque entrée est annotée (état consolidé,
 * confidentialité, `can_download`) et REDACTÉE si l'acteur n'est pas autorisé : il ne découvre
 * ni l'existence du fichier ni ses métadonnées sensibles.
 */
class SubjectDocumentReadModel
{
    public static function get(Company $company, DocumentSubject $subject, ?User $actor): array
    {
        // 1. Catalogue des types company_user activés (sans rôle — l'employé sans compte n'en a pas).
        $documents = DocumentResolverService::resolve(
            null,
            $company->id,
            null,
            $company->market_key,
            scope: DocumentType::SCOPE_COMPANY_USER,
        );

        if (empty($documents)) {
            return ['documents' => [], 'subject' => (string) $subject];
        }

        // 2. Uploads réels de CE sujet (clé = document_type_id).
        $codes = array_column($documents, 'code');
        $typesByCode = DocumentType::whereIn('code', $codes)->get()->keyBy('code');

        $uploads = MemberDocument::where('company_id', $company->id)
            ->where('document_subject_type', $subject->type)
            ->where('document_subject_id', $subject->id)
            ->get()
            ->keyBy('document_type_id');

        $policy = app(DocumentAccessPolicy::class);

        foreach ($documents as &$doc) {
            $type = $typesByCode->get($doc['code']);
            $upload = $type ? $uploads->get($type->id) : null;

            // Fusionne l'upload réel (le resolver avait upload=null car user=null).
            if ($upload) {
                $doc['upload'] = [
                    'id' => $upload->id,
                    'file_name' => $upload->file_name,
                    'file_size_bytes' => $upload->file_size_bytes,
                    'mime_type' => $upload->mime_type,
                    'expires_at' => $upload->expires_at?->toIso8601String(),
                    'uploaded_at' => $upload->created_at?->toIso8601String(),
                ];
                $doc['lifecycle_status'] = \App\Core\Documents\DocumentLifecycleService::computeStatus([
                    'expires_at' => $upload->expires_at,
                ]);
            }

            $hasFile = $upload !== null && ! empty($upload->file_path);

            // Pas de workflow de demande pour un sujet sans compte → validation not_required.
            $state = State::evaluateFromParts($hasFile, null, $doc['lifecycle_status'] ?? 'missing');
            $doc['request_status'] = null;
            $doc['has_file'] = $hasFile;
            $doc['can_review'] = false;
            $doc['compliance_status'] = $state['consolidated'];
            $doc['satisfies_obligation'] = $state['satisfies_obligation'];
            $doc['confidentiality'] = DocumentConfidentiality::forTypeCode($doc['code']);
            $doc['is_highly_confidential'] = $doc['confidentiality'] === DocumentConfidentiality::HIGHLY_CONFIDENTIAL;

            $canView = $actor !== null
                && $policy->canAccess($actor, $company, $subject, $doc['code'], DocumentAccessPolicy::OP_VIEW_METADATA);
            $doc['can_download'] = $hasFile && $canView;

            // Redaction : acteur non autorisé → ne révèle ni l'existence ni les métadonnées.
            if (! $canView) {
                $doc = self::redact($doc);
            }
        }
        unset($doc);

        return ['documents' => $documents, 'subject' => (string) $subject];
    }

    /** Masque l'upload et l'existence du fichier pour un slot non autorisé. */
    public static function redact(array $doc): array
    {
        $doc['upload'] = null;
        $doc['has_file'] = false;
        $doc['can_download'] = false;
        $doc['compliance_status'] = 'restricted';
        $doc['lifecycle_status'] = 'missing';
        $doc['satisfies_obligation'] = false;
        $doc['restricted'] = true;

        return $doc;
    }
}
