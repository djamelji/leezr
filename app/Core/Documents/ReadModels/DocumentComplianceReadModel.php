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
        $slots = self::slotsFor($company);

        return $slots === [] ? self::emptyResult() : self::aggregate($slots);
    }

    /**
     * ADR-790 (L0) — raw compliance slots (one per required document and subject), computed with the
     * SAME decision as the person views : DocumentResolverService::requirementMap, per person, with
     * THAT person's role (membership) ; a person without account has no role → Company rules only.
     * The role is never turned into a job : it only carries the rules that already applied to it.
     *
     * @return list<array<string, mixed>>
     */
    public static function slotsFor(Company $company): array
    {
        $companyId = $company->id;
        $marketKey = $company->market_key;

        // Décision partagée, mémoïsée par rôle pour ce calcul (quelques rôles, pas une requête par personne).
        $maps = [];
        $mapFor = function (?string $roleKey) use (&$maps, $companyId, $marketKey) {
            return $maps[$roleKey ?? ''] ??= \App\Core\Documents\DocumentResolverService::requirementMap($companyId, $roleKey, $marketKey);
        };

        $base = $mapFor(null);
        if ($base === []) {
            return [];
        }
        $types = collect($base)->map(fn ($e) => $e['type']);
        $required = fn (array $map, int $typeId) => isset($map[$typeId]) && $map[$typeId]['visible'] && $map[$typeId]['required'];

        $companyTypeIds = collect($base)->filter(fn ($e, $id) => $e['type']->scope === DocumentType::SCOPE_COMPANY && $required($base, $id))->keys();

        // Population (dédupliquée par sujet) et ensemble des types qu'au moins une personne doit fournir.
        $population = \App\Core\Documents\Services\PersonPopulationResolver::forCompany($company);
        $requiredByPerson = [];
        foreach ($population as $i => $person) {
            $map = $mapFor($person['role_key'] ?? null);
            $requiredByPerson[$i] = collect($map)->filter(fn ($e, $id) => $e['type']->scope === DocumentType::SCOPE_COMPANY_USER && $required($map, $id))->keys()->all();
        }
        $userTypeIds = collect($requiredByPerson)->flatten()->unique()->values();

        if ($companyTypeIds->isEmpty() && $userTypeIds->isEmpty()) {
            return [];
        }

        // ── Company-scope slots (pas de workflow de validation → not_required) ──
        $companyDocs = CompanyDocument::where('company_id', $companyId)
            ->whereIn('document_type_id', $companyTypeIds)
            ->get()
            ->keyBy('document_type_id');

        $companyRequests = \App\Core\Documents\DocumentRequest::where('company_id', $companyId)
            ->where('document_subject_type', \App\Core\Documents\DocumentSubject::TYPE_COMPANY)->get()->keyBy('document_type_id');

        $allSlots = [];

        foreach ($companyTypeIds as $typeId) {
            $type = $types->get($typeId);
            $doc = $companyDocs->get($typeId);
            // ADR-772 : une demande de renouvellement en cours sur le document de l'entreprise compte.
            $companyRequest = $companyRequests->get($typeId);
            $state = State::evaluate($doc, in_array($companyRequest?->status, ['submitted', 'in_review', 'rejected', 'to_replace'], true) ? $companyRequest->status : null);

            $allSlots[] = self::slot($typeId, $type, DocumentType::SCOPE_COMPANY, null, null, null, $state);
        }

        // ── User-scope slots : population DÉDUPLIQUÉE par SUJET (ADR-761 P0) ──
        // Compte les Employees (avec OU sans compte) + les Users sans Employee, une seule fois.
        if ($population->isNotEmpty() && $userTypeIds->isNotEmpty()) {
            // Uploads + demandes indexés par SUJET (couvre user ET employee ; fallback user_id
            // pour les lignes legacy sans colonnes sujet).
            $subjectKey = function ($row) {
                $st = $row->document_subject_type ?: ($row->user_id ? 'user' : null);
                $sid = $row->document_subject_id ?: $row->user_id;

                return $st.':'.$sid.':'.$row->document_type_id;
            };
            $docsBySubject = MemberDocument::where('company_id', $companyId)
                ->whereIn('document_type_id', $userTypeIds)
                ->get()
                ->keyBy($subjectKey);

            $requestsBySubject = DocumentRequest::where('company_id', $companyId)
                ->whereIn('document_type_id', $userTypeIds)
                ->get()
                ->keyBy($subjectKey);

            foreach ($population as $i => $person) {
                /** @var \App\Core\Documents\DocumentSubject $subject */
                $subject = $person['subject'];

                foreach ($requiredByPerson[$i] as $typeId) { // ADR-790 : SES exigences, pas celles de la Company entière
                    $type = $types->get($typeId);
                    $k = $subject->type.':'.$subject->id.':'.$typeId;
                    $doc = $docsBySubject->get($k);
                    $requestStatus = $requestsBySubject->get($k)?->status;
                    $state = State::evaluate($doc, $requestStatus);

                    $slot = self::slot($typeId, $type, DocumentType::SCOPE_COMPANY_USER, $person['role_key'], $person['role_name'], $person['user_id'], $state);
                    $slot['subject'] = (string) $subject;
                    $slot['employee_id'] = $person['employee_id'];
                    $slot['department_id'] = $person['department_id'];
                    $slot['account_less'] = $person['account_less'];
                    $allSlots[] = $slot;
                }
            }
        }

        return $allSlots;
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
