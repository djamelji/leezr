<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\MemberDocument;

/**
 * ADR-756 (DMS M0) — état documentaire MULTI-DIMENSIONNEL. Ne confond PAS les dimensions
 * (le défaut de l'ancien système) : Validation, Validité, Demande, Publication sont séparées,
 * SANS nouvelle table par statut (dérivées des données réelles). Expose un état de conformité
 * CONSOLIDÉ : un document refusé ou en attente de validation ne satisfait PAS une obligation.
 */
class DocumentComplianceStateService
{
    // A. Validation
    public const V_NOT_REQUIRED = 'not_required';
    public const V_PENDING = 'pending';
    public const V_APPROVED = 'approved';
    public const V_REJECTED = 'rejected';
    // B. Validité
    public const L_NOT_APPLICABLE = 'not_applicable';
    public const L_VALID = 'valid';
    public const L_EXPIRING = 'expiring';
    public const L_EXPIRED = 'expired';
    // C. Demande
    public const R_NONE = 'none';
    public const R_REQUESTED = 'requested';
    public const R_SUBMITTED = 'submitted';
    public const R_FULFILLED = 'fulfilled';
    public const R_OVERDUE = 'overdue';
    public const R_CANCELLED = 'cancelled';
    // D. Publication
    public const P_DRAFT = 'draft';
    public const P_PUBLISHED = 'published';
    public const P_ARCHIVED = 'archived';

    /**
     * @param  MemberDocument|null  $doc  le document du coffre (null = aucun fichier)
     * @param  string|null  $requestStatus  statut de la demande associée (document_requests.status)
     * @return array{validation:string,validity:string,request:string,publication:string,has_file:bool,satisfies_obligation:bool}
     */
    public static function evaluate(?MemberDocument $doc, ?string $requestStatus = null): array
    {
        $hasFile = $doc !== null && ! empty($doc->file_path);

        // B. Validité (réutilise DocumentLifecycleService)
        $life = DocumentLifecycleService::computeStatus($hasFile ? ['expires_at' => $doc->expires_at] : null);
        $validity = match ($life) {
            DocumentLifecycleService::STATUS_VALID => self::L_VALID,
            DocumentLifecycleService::STATUS_EXPIRING_SOON => self::L_EXPIRING,
            DocumentLifecycleService::STATUS_EXPIRED => self::L_EXPIRED,
            default => self::L_NOT_APPLICABLE, // missing
        };

        // A. Validation (depuis la demande)
        $validation = match ($requestStatus) {
            'submitted' => self::V_PENDING,
            'approved' => self::V_APPROVED,
            'rejected' => self::V_REJECTED,
            default => self::V_NOT_REQUIRED, // requested / cancelled / null
        };

        // C. Demande
        $request = match ($requestStatus) {
            'requested' => self::R_REQUESTED,
            'submitted' => self::R_SUBMITTED,
            'approved' => self::R_FULFILLED,
            'cancelled' => self::R_CANCELLED,
            'rejected' => self::R_OVERDUE,
            default => self::R_NONE,
        };

        // D. Publication
        $publication = $doc->published_state ?? self::P_PUBLISHED;

        // Consolidé : satisfait l'obligation ssi fichier présent, non expiré, validé (ou non
        // soumis à validation) et PUBLIÉ. Un refus ou une attente de validation NE satisfait PAS.
        $satisfies = $hasFile
            && in_array($validity, [self::L_VALID, self::L_EXPIRING], true)
            && ! in_array($validation, [self::V_PENDING, self::V_REJECTED], true)
            && $publication === self::P_PUBLISHED;

        return [
            'validation' => $validation,
            'validity' => $validity,
            'request' => $request,
            'publication' => $publication,
            'has_file' => $hasFile,
            'satisfies_obligation' => $satisfies,
        ];
    }
}
