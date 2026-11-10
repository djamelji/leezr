<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentMandatoryContext;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentComplianceStateService as State;
use App\Core\Models\Company;
use App\Core\Models\Membership;

/**
 * ADR-387 / ADR-757 (DMS M1): Document compliance dashboard.
 *
 * Rebranché sur DocumentComplianceStateService : la conformité consulte DÉSORMAIS le workflow
 * de validation (document_requests). Un document SOUMIS/REFUSÉ ne compte plus comme conforme —
 * seul `satisfies_obligation` (fichier présent + non expiré + non en attente/refusé + publié)
 * alimente le taux. L'état par slot est CONSOLIDÉ en une étiquette priorisée distinguant :
 * missing · requested · pending_validation · rejected · valid · expiring_soon · expired.
 *
 * Query budget: 6 (types, activations, company_docs, memberships, member_docs, requests).
 */
class DocumentComplianceReadModel
{
    /** Toutes les étiquettes consolidées suivies dans les agrégats. */
    private const STATUSES = [
        State::C_MISSING, State::C_REQUESTED, State::C_PENDING, State::C_REJECTED,
        State::C_VALID, State::C_EXPIRING, State::C_EXPIRED,
    ];

    public static function forCompany(Company $company): array
    {
        $companyId = $company->id;
        $marketKey = $company->market_key;

        $types = DocumentType::where(function ($q) use ($companyId) {
            $q->where('is_system', true)->orWhere('company_id', $companyId);
        })->whereNull('archived_at')->get()->keyBy('id');

        if ($types->isEmpty()) {
            return self::emptyResult();
        }

        if ($marketKey) {
            $types = $types->filter(function ($type) use ($marketKey) {
                $markets = $type->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        $activations = DocumentTypeActivation::whereIn('document_type_id', $types->keys())
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->get()
            ->keyBy('document_type_id');

        if ($activations->isEmpty()) {
            return self::emptyResult();
        }

        $context = DocumentMandatoryContext::load($companyId);

        $requiredTypeIds = $activations->filter(function ($activation) use ($types, $context) {
            $type = $types->get($activation->document_type_id);
            if (! $type) {
                return false;
            }
            $mandatory = DocumentMandatoryContext::isMandatory($type, $context);

            return $mandatory || $activation->required_override;
        })->keys();

        if ($requiredTypeIds->isEmpty()) {
            return self::emptyResult();
        }

        $companyTypeIds = $requiredTypeIds->filter(
            fn ($id) => $types->get($id)?->scope === DocumentType::SCOPE_COMPANY,
        );
        $userTypeIds = $requiredTypeIds->filter(
            fn ($id) => $types->get($id)?->scope === DocumentType::SCOPE_COMPANY_USER,
        );

        // ── Company-scope slots (pas de workflow de validation → not_required) ──
        $companyDocs = CompanyDocument::where('company_id', $companyId)
            ->whereIn('document_type_id', $companyTypeIds)
            ->get()
            ->keyBy('document_type_id');

        $allSlots = [];

        foreach ($companyTypeIds as $typeId) {
            $type = $types->get($typeId);
            $doc = $companyDocs->get($typeId);
            $state = State::evaluate($doc, null);

            $allSlots[] = self::slot($typeId, $type, DocumentType::SCOPE_COMPANY, null, null, null, $state);
        }

        // ── User-scope slots ──
        $memberships = Membership::where('company_id', $companyId)
            ->with('companyRole')
            ->get();

        if ($memberships->isNotEmpty() && $userTypeIds->isNotEmpty()) {
            $memberDocs = MemberDocument::where('company_id', $companyId)
                ->whereIn('document_type_id', $userTypeIds)
                ->get()
                ->groupBy('user_id');

            // Statut de demande par (user_id, type_id) pour la dimension Validation.
            $requests = DocumentRequest::where('company_id', $companyId)
                ->whereIn('document_type_id', $userTypeIds)
                ->get()
                ->keyBy(fn ($r) => $r->user_id.':'.$r->document_type_id);

            foreach ($memberships as $membership) {
                $roleKey = $membership->companyRole?->key;
                $roleName = $membership->companyRole?->name;
                $userDocsByType = ($memberDocs->get($membership->user_id) ?? collect())
                    ->keyBy('document_type_id');

                foreach ($userTypeIds as $typeId) {
                    $type = $types->get($typeId);
                    $doc = $userDocsByType->get($typeId);
                    $requestStatus = $requests->get($membership->user_id.':'.$typeId)?->status;
                    $state = State::evaluate($doc, $requestStatus);

                    $allSlots[] = self::slot(
                        $typeId, $type, DocumentType::SCOPE_COMPANY_USER,
                        $roleKey, $roleName, $membership->user_id, $state,
                    );
                }
            }
        }

        return self::aggregate($allSlots);
    }

    private static function slot(int $typeId, $type, string $scope, ?string $roleKey, ?string $roleName, ?int $userId, array $state): array
    {
        return [
            'type_id' => $typeId,
            'code' => $type->code,
            'label' => $type->label,
            'scope' => $scope,
            'role_key' => $roleKey,
            'role_name' => $roleName,
            'user_id' => $userId,
            // Rétro-compat : la validité "lifecycle" seule.
            'lifecycle_status' => $state['validity'] === State::L_NOT_APPLICABLE ? 'missing' : $state['validity'],
            // Nouvel état consolidé priorisé (workflow inclus).
            'compliance_status' => $state['consolidated'],
            'satisfies' => $state['satisfies_obligation'],
        ];
    }

    private static function aggregate(array $slots): array
    {
        $total = count($slots);
        $counts = array_fill_keys(self::STATUSES, 0);
        $satisfiedTotal = 0;

        foreach ($slots as $slot) {
            $counts[$slot['compliance_status']]++;
            if ($slot['satisfies']) {
                $satisfiedTotal++;
            }
        }

        $rate = $total > 0 ? round($satisfiedTotal / $total * 100, 1) : 0;

        // ── By role (user-scope slots only) ──
        $byRole = [];
        foreach ($slots as $slot) {
            if ($slot['scope'] !== DocumentType::SCOPE_COMPANY_USER) {
                continue;
            }
            $key = $slot['role_key'] ?? '_none';

            if (! isset($byRole[$key])) {
                $byRole[$key] = self::bucket(['role_key' => $slot['role_key'], 'role_name' => $slot['role_name'], 'members' => []]);
            }

            $byRole[$key]['members'][$slot['user_id']] = true;
            $byRole[$key]['total']++;
            $byRole[$key][$slot['compliance_status']]++;
            if ($slot['satisfies']) {
                $byRole[$key]['_satisfied']++;
            }
        }

        foreach ($byRole as &$r) {
            $r['member_count'] = count($r['members']);
            unset($r['members']);
            $r['rate'] = $r['total'] > 0 ? round($r['_satisfied'] / $r['total'] * 100, 1) : 0;
            unset($r['_satisfied']);
        }
        unset($r);

        // ── By type ──
        $byType = [];
        foreach ($slots as $slot) {
            $code = $slot['code'];

            if (! isset($byType[$code])) {
                $byType[$code] = self::bucket(['code' => $code, 'label' => $slot['label'], 'scope' => $slot['scope']]);
            }

            $byType[$code]['total']++;
            $byType[$code][$slot['compliance_status']]++;
            if ($slot['satisfies']) {
                $byType[$code]['_satisfied']++;
            }
        }

        foreach ($byType as &$t) {
            $t['rate'] = $t['total'] > 0 ? round($t['_satisfied'] / $t['total'] * 100, 1) : 0;
            unset($t['_satisfied']);
        }
        unset($t);

        return [
            'summary' => array_merge(
                ['total' => $total],
                self::statusCounts($counts),
                ['rate' => $rate],
            ),
            'by_role' => array_values($byRole),
            'by_type' => array_values($byType),
        ];
    }

    /** Bucket pré-initialisé avec tous les compteurs d'état à 0. */
    private static function bucket(array $base): array
    {
        return array_merge($base, ['total' => 0, '_satisfied' => 0], array_fill_keys(self::STATUSES, 0));
    }

    /**
     * Compteurs d'état. Conserve les clés historiques (valid/missing/expiring_soon/expired) pour
     * la rétro-compatibilité des widgets + ajoute les nouvelles (requested/pending_validation/rejected).
     */
    private static function statusCounts(array $counts): array
    {
        return [
            'valid' => $counts[State::C_VALID],
            'missing' => $counts[State::C_MISSING],
            'requested' => $counts[State::C_REQUESTED],
            'pending_validation' => $counts[State::C_PENDING],
            'rejected' => $counts[State::C_REJECTED],
            'expiring_soon' => $counts[State::C_EXPIRING],
            'expired' => $counts[State::C_EXPIRED],
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'summary' => array_merge(['total' => 0], self::statusCounts(array_fill_keys(self::STATUSES, 0)), ['rate' => 0]),
            'by_role' => [],
            'by_type' => [],
        ];
    }
}
