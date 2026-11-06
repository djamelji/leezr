<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Models\Company;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;
use App\Modules\Workforce\Services\OverstaffingEvaluator;
use App\Modules\Workforce\Services\OverstaffingPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrateur M4 (ADR-727) — RÉPÉTITION d'une semaine type sur plusieurs semaines.
 *
 * Construit AU-DESSUS de M3 (ApplyWeekTemplateUseCase) : applique chaque semaine SÉQUENTIELLEMENT dans UNE
 * SEULE transaction, de sorte que les validateurs M3 (ShiftRuleValidator repos/max, chevauchement, absence,
 * contrat) voient les shifts des semaines PRÉCÉDENTES → contraintes CUMULATIVES réelles sur le calendrier
 * (ex. repos entre dimanche S1 et lundi S2, heures cumulées, conflits inter-semaines).
 *
 * POLITIQUE TRANSACTIONNELLE = A (transaction globale atomique) : aucun Shift partiellement matérialisé,
 * aucun résultat silencieusement incomplet, bilan par semaine, retries idempotents (chevauchement → 0
 * doublon), Undo d'une opération UNIQUE. Plafond de volume explicite (MAX_REPEAT_CELLS) : au-delà, refus
 * clair plutôt qu'une application non atomique ou tronquée. (Comparaison A/B/C documentée dans l'ADR-727.)
 *
 * Prévisualisation = DRY-RUN : les semaines sont réellement créées (sans matérialiser les activités, inutiles
 * aux contrôles) puis la transaction est ANNULÉE → bilan cumulatif exact, zéro écriture. Sureffectif évalué
 * une seule fois sur l'ENSEMBLE des semaines (confirmation serveur selon la politique Company).
 *
 * Aucune publication automatique. Ne crée AUCUN CoverageRequirement (les besoins exprimés ≠ couverture
 * opérationnelle). Ne modifie jamais un Shift publié.
 */
class RepeatWeekTemplateUseCase
{
    /**
     * Plafond de volume (cellules = affectations potentielles) d'une répétition. Mesuré (cf. ADR-727 §charge).
     * Au-delà : refus explicite (jamais une application partielle/tronquée en silence).
     */
    public const MAX_REPEAT_CELLS = 2000;

    public const HORIZONS = ['1', '2', '4', '8', 'eom'];

    private function signal(): \RuntimeException
    {
        return new class('repeat-dry-run-rollback') extends \RuntimeException {};
    }

    /**
     * Dates de début des semaines cibles (premier jour de semaine Company respecté).
     * eom = la 1re semaine + toutes les semaines dont le DÉBUT tombe au plus tard le dernier jour du mois
     * de la date choisie, dans la limite de 8 semaines (convention affichée dans l'UI et testée).
     *
     * @return list<Carbon>
     */
    public function weekDates(Company $company, string $firstWeekDate, string $horizon): array
    {
        $apply = app(ApplyWeekTemplateUseCase::class);
        $ws0 = $apply->resolveWeekStart($company, $firstWeekDate);

        if ($horizon === 'eom') {
            $endMonth = Carbon::parse($firstWeekDate)->endOfMonth();
            $dates = [$ws0->copy()];
            for ($i = 1; $i < 8; $i++) {
                $ws = $ws0->copy()->addWeeks($i);
                if ($ws->lte($endMonth)) {
                    $dates[] = $ws;
                } else {
                    break;
                }
            }

            return $dates;
        }

        $n = max(1, min(8, (int) $horizon));

        return array_map(fn ($i) => $ws0->copy()->addWeeks($i), range(0, $n - 1));
    }

    /** Estime le volume de cellules et refuse au-delà du plafond (avant toute écriture, même dry-run). */
    private function guardVolume(int $companyId, int $weekTemplateId, array $weeks): void
    {
        $wt = WeekTemplate::withoutGlobalScopes()->where('id', $weekTemplateId)->where('company_id', $companyId)->first();
        if (! $wt) {
            throw new \DomainException('Week template not found or does not belong to this company.');
        }
        $perWeek = 0;
        foreach (WeekTemplateLine::withoutGlobalScopes()->where('week_template_id', $weekTemplateId)->get() as $ln) {
            $perWeek += $ln->kind === 'needs' ? max(1, (int) $ln->headcount) : 1;
        }
        $total = $perWeek * count($weeks);
        if ($total > self::MAX_REPEAT_CELLS) {
            throw new \DomainException("Repeat volume exceeds the limit ({$total} > ".self::MAX_REPEAT_CELLS.'). Reduce the horizon or the week template size.');
        }
    }

    /**
     * Prévisualisation globale + par semaine, CUMULATIVE (dry-run annulé). $assignments pour les besoins :
     * [['week_index'=>int,'line_id'=>int,'employee_id'=>int], ...].
     */
    public function preview(int $companyId, int $weekTemplateId, string $firstWeekDate, string $horizon, array $assignments, ?array $visibleIds, ?int $actorId): array
    {
        $company = Company::findOrFail($companyId);
        $weeks = $this->weekDates($company, $firstWeekDate, $horizon);
        $this->guardVolume($companyId, $weekTemplateId, $weeks);
        $apply = app(ApplyWeekTemplateUseCase::class);

        $collected = ['weeks' => [], 'prospects' => []];
        $sig = $this->signal();
        try {
            DB::transaction(function () use (&$collected, $weeks, $apply, $companyId, $weekTemplateId, $assignments, $visibleIds, $actorId, $sig) {
                foreach ($weeks as $i => $ws) {
                    $date = $ws->toDateString();
                    // UNE seule passe (detailed) : classe + liste les candidats des besoins ET avance l'état
                    // (crée nominatif + besoins affectés, SANS activités) → cumulatif pour la semaine suivante.
                    $res = $apply->commit($companyId, $weekTemplateId, $date, $this->forWeek($assignments, $i), $visibleIds, null, null, $actorId, skipOverstaffing: true, materialize: false, detailed: true);
                    $collected['weeks'][] = [
                        'week_index' => $i, 'week_start' => $date,
                        'summary' => [
                            'applicable' => $res['created_count'],
                            'to_confirm' => array_sum(array_map(fn ($n) => (int) ($n['headcount'] ?? 0), $res['needs'])),
                            'blocked' => count($res['blocked']),
                        ],
                        'nominative' => $res['nominative'], 'needs' => $res['needs'],
                    ];
                    $collected['prospects'] = array_merge($collected['prospects'], $res['prospects']);
                }
                throw $sig; // annule le dry-run
            });
        } catch (\RuntimeException $e) {
            if ($e !== $sig) {
                throw $e; // vraie erreur → propagée (le dry-run a bien été annulé par la transaction)
            }
        }

        $overstaffing = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $collected['prospects']);
        $global = [
            'weeks' => count($weeks),
            'applicable' => array_sum(array_map(fn ($w) => $w['summary']['applicable'], $collected['weeks'])),
            'to_confirm' => array_sum(array_map(fn ($w) => $w['summary']['to_confirm'], $collected['weeks'])),
            'blocked' => array_sum(array_map(fn ($w) => $w['summary']['blocked'], $collected['weeks'])),
        ];

        return [
            'week_template_id' => $weekTemplateId, 'horizon' => $horizon,
            'week_dates' => array_map(fn ($w) => $w->toDateString(), $weeks),
            'eom_convention' => 'weeks whose start is on or before the last day of the selected month (max 8)',
            'global' => $global, 'weeks' => $collected['weeks'],
            'overstaffing' => $overstaffing, 'mode' => app(OverstaffingPolicy::class)->mode($companyId),
        ];
    }

    /**
     * Application ATOMIQUE GLOBALE (une seule transaction). Retourne created (tous), bilan par semaine,
     * total non pourvu/bloqué. L'opération réversible unique est enregistrée par le contrôleur.
     */
    public function commit(int $companyId, int $weekTemplateId, string $firstWeekDate, string $horizon, array $assignments, ?array $visibleIds, ?string $ack, ?string $reason, ?int $actorId): array
    {
        $company = Company::findOrFail($companyId);
        $weeks = $this->weekDates($company, $firstWeekDate, $horizon);
        $this->guardVolume($companyId, $weekTemplateId, $weeks);
        $apply = app(ApplyWeekTemplateUseCase::class);

        return DB::transaction(function () use ($weeks, $apply, $companyId, $weekTemplateId, $assignments, $visibleIds, $ack, $reason, $actorId) {
            $created = [];
            $prospects = [];
            $perWeek = [];
            $unfilled = 0;
            $blocked = [];

            foreach ($weeks as $i => $ws) {
                $date = $ws->toDateString();
                $res = $apply->commit($companyId, $weekTemplateId, $date, $this->forWeek($assignments, $i), $visibleIds, null, null, $actorId, skipOverstaffing: true, materialize: true);
                $created = array_merge($created, $res['created']);
                $prospects = array_merge($prospects, $res['prospects']);
                $unfilled += $res['unfilled'];
                $blocked = array_merge($blocked, $res['blocked']);
                $perWeek[] = ['week_index' => $i, 'week_start' => $date, 'created' => $res['created_count'], 'unfilled' => $res['unfilled'], 'blocked' => count($res['blocked'])];
            }

            // SUREFFECTIF CUMULATIF sur TOUTES les semaines — une seule confirmation serveur (politique Company).
            $eval = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);
            if ($eval['overstaffed']) {
                $policy = app(OverstaffingPolicy::class);
                $mode = $policy->mode($companyId);
                if ($policy->requiresConfirmation($mode)) {
                    $needsReason = $policy->requiresReason($mode);
                    if (! ($ack !== null && hash_equals($eval['signature'], $ack)) || ($needsReason && trim((string) $reason) === '')) {
                        throw new OverstaffingConfirmationRequired($eval['impacts'], $eval['signature'], $mode, $needsReason);
                    }
                }
            }

            return [
                'created' => $created, 'created_count' => count($created),
                'unfilled' => $unfilled, 'blocked' => $blocked, 'weeks' => $perWeek,
            ];
        });
    }

    /** @return list<array{line_id:int,employee_id:int}> assignments de la semaine $i (sans week_index) */
    private function forWeek(array $assignments, int $i): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if ((int) ($a['week_index'] ?? 0) === $i && ! empty($a['line_id']) && ! empty($a['employee_id'])) {
                $out[] = ['line_id' => (int) $a['line_id'], 'employee_id' => (int) $a['employee_id']];
            }
        }

        return $out;
    }
}
