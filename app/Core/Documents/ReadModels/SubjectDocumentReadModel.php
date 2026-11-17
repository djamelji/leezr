<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentLifecycleService;
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
 * ADR-758 (M1.1) / ADR-759 (M2) — projection documentaire UNIFIÉE par SUJET(S). Fusionne les
 * documents d'une personne rattachés à `employee:{id}` ET `user:{id}` (continuité des dossiers) —
 * liens EXPLICITES uniquement, jamais par nom/e-mail, aucun déplacement de fichier, aucun doublon
 * fonctionnel (dédup par type, on garde la pièce portée par un fichier). Annote l'état consolidé,
 * la confidentialité, la demande (statut/échéance/message/motif) et `can_download`, avec REDACTION
 * si l'acteur n'est pas autorisé (ne découvre ni l'existence ni les métadonnées sensibles).
 *
 * @param  list<DocumentSubject>  $subjects  tous les sujets explicites de la personne
 */
class SubjectDocumentReadModel
{
    public static function get(Company $company, DocumentSubject $subject, ?User $actor): array
    {
        return self::forSubjects($company, [$subject], $subject, null, null, $actor);
    }

    /**
     * @param  list<DocumentSubject>  $subjects
     */
    public static function forSubjects(Company $company, array $subjects, DocumentSubject $primary, ?User $userForCatalog, ?string $roleKey, ?User $actor): array
    {
        // 1. Catalogue des types company_user (role-aware si compte présent).
        $documents = DocumentResolverService::resolve(
            $userForCatalog,
            $company->id,
            $roleKey,
            $company->market_key,
            scope: DocumentType::SCOPE_COMPANY_USER,
        );

        if (empty($documents)) {
            return ['documents' => [], 'subject' => (string) $primary];
        }

        $codes = array_column($documents, 'code');
        $typesByCode = DocumentType::whereIn('code', $codes)->get()->keyBy('code');
        $typeIds = $typesByCode->pluck('id')->all();

        // 2. Uploads sur TOUS les sujets, dédupliqués par type (priorité à la pièce avec fichier).
        $subjectPairs = array_map(fn ($s) => [$s->type, $s->id], $subjects);
        $uploads = MemberDocument::where('company_id', $company->id)
            ->whereIn('document_type_id', $typeIds)
            ->where(function ($q) use ($subjectPairs) {
                foreach ($subjectPairs as [$t, $id]) {
                    $q->orWhere(fn ($w) => $w->where('document_subject_type', $t)->where('document_subject_id', $id));
                }
            })
            ->get();
        $uploadsByType = [];
        foreach ($uploads as $u) {
            $cur = $uploadsByType[$u->document_type_id] ?? null;
            if ($cur === null || (empty($cur->file_path) && ! empty($u->file_path))) {
                $uploadsByType[$u->document_type_id] = $u;
            }
        }

        // 3. Demandes sur TOUS les sujets, par type (priorité à la plus récemment active).
        $requests = DocumentRequest::where('company_id', $company->id)
            ->whereIn('document_type_id', $typeIds)
            ->where(function ($q) use ($subjectPairs) {
                foreach ($subjectPairs as [$t, $id]) {
                    $q->orWhere(fn ($w) => $w->where('document_subject_type', $t)->where('document_subject_id', $id));
                }
            })
            ->orderByDesc('updated_at')
            ->get();
        $requestByType = [];
        foreach ($requests as $r) {
            $requestByType[$r->document_type_id] ??= $r;
        }

        $policy = app(DocumentAccessPolicy::class);
        $proofs = \App\Core\Documents\Satisfaction\DocumentProofRegistry::forSubjects($company, $subjects); // ADR-819

        foreach ($documents as &$doc) {
            $type = $typesByCode->get($doc['code']);
            $upload = $type ? ($uploadsByType[$type->id] ?? null) : null;
            $request = $type ? ($requestByType[$type->id] ?? null) : null;

            if ($upload) {
                $doc['upload'] = [
                    'id' => $upload->id,
                    'file_name' => $upload->file_name,
                    'file_size_bytes' => $upload->file_size_bytes,
                    'mime_type' => $upload->mime_type,
                    'expires_at' => $upload->expires_at?->toIso8601String(),
                    'uploaded_at' => $upload->created_at?->toIso8601String(),
                ];
                $doc['lifecycle_status'] = DocumentLifecycleService::computeStatus(['expires_at' => $upload->expires_at]);
            }

            $hasFile = $upload !== null && ! empty($upload->file_path);
            $state = State::evaluateFromParts($hasFile, $request?->status, $doc['lifecycle_status'] ?? 'missing');
            $doc['satisfied_by'] = null;
            if (! $state['satisfies_obligation'] && ($proof = $proofs[$doc['code']] ?? null)) {
                $state = State::fromProof(); // ADR-819 : satisfaite par une autre source compatible
                $doc['satisfied_by'] = $proof;
            }

            $doc['request_status'] = $request?->status;
            $doc['request_review_note'] = $request?->review_note;
            $doc['request_message'] = $request?->message;
            $doc['due_date'] = $request?->due_date?->toDateString();
            $doc['is_overdue'] = (bool) $request?->isOverdue();
            $doc['has_file'] = $hasFile;
            // ADR-772 : une pièce soumise pour un salarié sans compte se valide comme celle d'un membre.
            $doc['can_review'] = $request?->status === \App\Core\Documents\DocumentRequest::STATUS_SUBMITTED;
            $doc['compliance_status'] = $state['consolidated'];
            $doc['satisfies_obligation'] = $state['satisfies_obligation'];
            $doc['confidentiality'] = DocumentConfidentiality::forTypeCode($doc['code']);
            $doc['is_highly_confidential'] = $doc['confidentiality'] === DocumentConfidentiality::HIGHLY_CONFIDENTIAL;

            $canView = $actor !== null
                && $policy->canAccess($actor, $company, $primary, $doc['code'], DocumentAccessPolicy::OP_VIEW_METADATA);
            $doc['can_download'] = $hasFile && $canView;

            if (! $canView) {
                $doc = self::redact($doc);
            }
        }
        unset($doc);

        return ['documents' => $documents, 'subject' => (string) $primary];
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
        $doc['satisfied_by'] = null; // ADR-819 : la source d'une preuve n'est pas révélée non plus

        return $doc;
    }
}
