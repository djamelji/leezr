<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentLifecycleService;
use Illuminate\Database\Eloquent\Model;

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
     * @param  Model|null  $doc  le document du coffre (MemberDocument|CompanyDocument ; null = aucun fichier)
     * @param  string|null  $requestStatus  statut de la demande associée (document_requests.status)
     * @return array{validation:string,validity:string,request:string,publication:string,has_file:bool,satisfies_obligation:bool,consolidated:string}
     */
    public static function evaluate(?Model $doc, ?string $requestStatus = null): array
    {
        $hasFile = $doc !== null && ! empty($doc->file_path);
        $lifecycle = DocumentLifecycleService::computeStatus($hasFile ? ['expires_at' => $doc->expires_at] : null);
        $publication = $doc?->published_state ?? self::P_PUBLISHED;

        return self::evaluateFromParts($hasFile, $requestStatus, $lifecycle, $publication);
    }

    /**
     * Même évaluation mais à partir de pièces déjà calculées (sans modèle Eloquent) — utilisé par
     * les read models qui disposent du `lifecycle_status` du resolver.
     *
     * @param  string  $lifecycle  statut DocumentLifecycleService (missing|valid|expiring_soon|expired)
     * @return array{validation:string,validity:string,request:string,publication:string,has_file:bool,satisfies_obligation:bool,consolidated:string}
     */
    public static function evaluateFromParts(bool $hasFile, ?string $requestStatus, string $lifecycle, string $publication = self::P_PUBLISHED): array
    {
        // B. Validité
        $validity = match ($lifecycle) {
            DocumentLifecycleService::STATUS_VALID => self::L_VALID,
            DocumentLifecycleService::STATUS_EXPIRING_SOON => self::L_EXPIRING,
            DocumentLifecycleService::STATUS_EXPIRED => self::L_EXPIRED,
            default => self::L_NOT_APPLICABLE, // missing
        };

        // A. Validation (depuis la demande)
        $validation = match ($requestStatus) {
            'submitted', 'in_review' => self::V_PENDING, // ADR-810.1 : « en vérification » attend aussi la validation
            'approved' => self::V_APPROVED,
            'rejected' => self::V_REJECTED,
            default => self::V_NOT_REQUIRED, // requested / cancelled / null
        };

        // C. Demande
        $request = match ($requestStatus) {
            'requested' => self::R_REQUESTED,
            'submitted', 'in_review' => self::R_SUBMITTED,
            'approved' => self::R_FULFILLED,
            'cancelled' => self::R_CANCELLED,
            'rejected' => self::R_OVERDUE,
            default => self::R_NONE,
        };

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
            'consolidated' => self::consolidate($hasFile, $validation, $validity, $request),
        ];
    }

    // État CONSOLIDÉ lisible (une seule étiquette priorisée) pour le dashboard et les fiches.
    public const C_MISSING = 'missing';
    public const C_REQUESTED = 'requested';
    public const C_PENDING = 'pending_validation';
    public const C_REJECTED = 'rejected';
    public const C_VALID = 'valid';
    public const C_EXPIRING = 'expiring_soon';
    public const C_EXPIRED = 'expired';

    /**
     * Réduit les 4 dimensions en UNE étiquette priorisée (un document ne peut afficher qu'un
     * état dominant). Un refus/une attente priment sur la validité ; l'absence prime sur tout.
     */
    public static function consolidate(bool $hasFile, string $validation, string $validity, string $request): string
    {
        if (! $hasFile) {
            return $request === self::R_REQUESTED ? self::C_REQUESTED : self::C_MISSING;
        }
        if ($validation === self::V_REJECTED) {
            return self::C_REJECTED;
        }
        if ($validation === self::V_PENDING) {
            return self::C_PENDING;
        }
        if ($validity === self::L_EXPIRED) {
            return self::C_EXPIRED;
        }
        if ($validity === self::L_EXPIRING) {
            return self::C_EXPIRING;
        }

        return self::C_VALID;
    }
}
