<?php

namespace App\Modules\Workforce\Services;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P1a (ADR-703) — CONTRÔLE RÉGLEMENTAIRE sur le TEMPS CONSTATÉ (feuille de temps).
 *
 * Détection & SIGNALEMENT uniquement (projection de CONSULTATION) : AUCUNE mutation de la feuille/ligne,
 * AUCUN blocage, AUCUNE approbation, AUCUNE correction automatique des heures. Les heures réellement
 * constatées sont intégralement conservées. Ce n'est PAS une décision réglementaire historisée.
 *
 * Consomme UNIQUEMENT les règles résolues par WorkforceRuleResolver (pas de 2ᵉ moteur normatif). Market
 * et convention restent la référence. Les heures supplémentaires ne sont PAS traitées ici (P0, « à qualifier »).
 *
 * Contrat de résultat par contrôle — statut AUTORITÉ SERVEUR (jamais déduit d'une couleur/message front) :
 *   pass | warning | violation | not_verifiable | not_applicable.
 *   not_verifiable n'est JAMAIS compté comme conforme.
 */
class WorkTimeComplianceChecker
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARNING = 'warning';
    public const STATUS_VIOLATION = 'violation';
    public const STATUS_NOT_VERIFIABLE = 'not_verifiable';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    private const FORFAIT_MODELS = ['forfait_jours', 'forfait_heures'];

    /** @return array<int, array<string, mixed>> liste de résultats de contrôle (lecture seule) */
    public function check(TimesheetPeriod $period): array
    {
        $company = Company::withoutGlobalScopes()->find($period->company_id);
        if (! $company) {
            return [];
        }
        $employee = Employee::withoutGlobalScopes()->find($period->employee_id);
        $contract = $employee?->currentContract;
        $market = $company->market_key;
        $at = Carbon::parse($period->period_end);
        $rules = app(WorkforceRuleResolver::class)->resolveWorkTimeRules($company, $at, $contract);
        $isForfait = in_array($contract?->work_model_key, self::FORFAIT_MODELS, true);
        $lines = $period->lines()->orderBy('date')->get();

        $results = [];
        foreach ($lines->where('worked_minutes', '>', 0) as $line) {
            $results[] = $this->dailyMax($line, $rules['daily_hours_max'] ?? null, $market, $isForfait, $contract?->work_model_key);
        }
        foreach ($this->dailyRest($lines, $rules['daily_rest_min_hours'] ?? null, $market) as $r) {
            $results[] = $r;
        }
        foreach ($this->weekly($period, $lines, $rules, $market, $isForfait, $contract?->work_model_key) as $r) {
            $results[] = $r;
        }

        return $results;
    }

    /** Résultat normalisé. */
    private function result(string $ruleKey, string $checkType, string $market, ?array $rule, int|float|null $thresholdMinutes, int|float|null $observedMinutes, ?string $periodLabel, string $status, string $message, array $trigger): array
    {
        return [
            'rule_key' => $ruleKey,
            'check_type' => $checkType,
            'market' => $market,
            'source' => $rule['source'] ?? null,            // law | convention | jobdomain | company
            'reference' => $rule['reference'] ?? null,       // ex. L3121-18
            'effective_date' => $rule['effective_from'] ?? null,
            'threshold_minutes' => $thresholdMinutes,
            'observed_minutes' => $observedMinutes,
            'period' => $periodLabel,
            'status' => $status,
            'message' => $message,
            'trigger' => $trigger,                            // données déclenchantes (audit)
        ];
    }

    // ── Durée maximale quotidienne (par jour travaillé) ──
    private function dailyMax(object $line, ?array $rule, string $market, bool $isForfait, ?string $workModel): array
    {
        $date = $line->date->toDateString();
        $observed = (int) $line->worked_minutes;

        if ($isForfait) {
            return $this->result('daily_hours_max', 'daily_max', $market, $rule, null, $observed, $date, self::STATUS_NOT_VERIFIABLE,
                "Régime « {$workModel} » : les maxima horaires standards ne s'appliquent pas tels quels ; régime non encore qualifié.", ['date' => $date, 'work_model' => $workModel]);
        }
        $threshold = $this->minutesOf($rule);
        if ($threshold === null) {
            return $this->result('daily_hours_max', 'daily_max', $market, $rule, null, $observed, $date, self::STATUS_NOT_VERIFIABLE,
                'Durée quotidienne maximale non configurée pour ce Market.', ['date' => $date]);
        }

        $status = $observed > $threshold ? self::STATUS_VIOLATION : self::STATUS_PASS;

        return $this->result('daily_hours_max', 'daily_max', $market, $rule, $threshold, $observed, $date, $status,
            $status === self::STATUS_VIOLATION ? 'Durée quotidienne constatée supérieure au maximum applicable.' : 'Durée quotidienne dans la limite.',
            ['date' => $date, 'worked_minutes' => $observed, 'threshold_minutes' => $threshold]);
    }

    // ── Repos quotidien entre deux PÉRIODES de travail (pas entre splits du même jour) ──
    private function dailyRest(Collection $lines, ?array $rule, string $market): array
    {
        $threshold = $this->minutesOf($rule);
        $worked = $lines->filter(fn ($l) => $l->worked_minutes > 0)->sortBy('date')->values();
        if ($worked->count() < 2) {
            return []; // pas de transition entre journées à évaluer
        }
        $out = [];
        for ($i = 0; $i < $worked->count() - 1; $i++) {
            $prevOut = $this->lastClockOut($worked[$i]);
            $nextIn = $this->firstClockIn($worked[$i + 1]);
            $label = $worked[$i]->date->toDateString().'→'.$worked[$i + 1]->date->toDateString();

            if ($threshold === null) {
                $out[] = $this->result('daily_rest_min_hours', 'daily_rest', $market, $rule, null, null, $label, self::STATUS_NOT_VERIFIABLE,
                    'Repos quotidien minimal non configuré pour ce Market.', ['transition' => $label]);

                continue;
            }
            if (! $prevOut || ! $nextIn) {
                $out[] = $this->result('daily_rest_min_hours', 'daily_rest', $market, $rule, $threshold, null, $label, self::STATUS_NOT_VERIFIABLE,
                    'Horaires de sortie/entrée incomplets — repos non vérifiable.', ['transition' => $label]);

                continue;
            }
            $gap = $prevOut->diffInMinutes($nextIn, false); // signé
            $status = ($gap >= 0 && $gap < $threshold) ? self::STATUS_VIOLATION : self::STATUS_PASS;
            $out[] = $this->result('daily_rest_min_hours', 'daily_rest', $market, $rule, $threshold, $gap, $label, $status,
                $status === self::STATUS_VIOLATION ? 'Repos entre deux journées inférieur au minimum applicable.' : 'Repos quotidien suffisant.',
                ['transition' => $label, 'rest_minutes' => $gap, 'threshold_minutes' => $threshold]);
        }

        return $out;
    }

    // ── Semaine RÉGLEMENTAIRE (≠ semaine d'affichage week_start_day) : max hebdo + repos hebdo ──
    private function weekly(TimesheetPeriod $period, Collection $lines, array $rules, string $market, bool $isForfait, ?string $workModel): array
    {
        // Période réglementaire : seul le Market FR est qualifié ici (semaine CIVILE lundi→dimanche, L3121).
        // Sinon : non vérifiable (ne pas présumer la période légale, ne pas utiliser la préférence d'affichage).
        if ($market !== 'FR') {
            return [[
                'rule_key' => 'weekly', 'check_type' => 'weekly', 'market' => $market, 'source' => null, 'reference' => null,
                'effective_date' => null, 'threshold_minutes' => null, 'observed_minutes' => null, 'period' => null,
                'status' => self::STATUS_NOT_VERIFIABLE, 'message' => 'Période de référence hebdomadaire non qualifiée pour ce Market.', 'trigger' => [],
            ]];
        }

        $start = Carbon::parse($period->period_start)->startOfDay();
        $end = Carbon::parse($period->period_end)->endOfDay();
        $out = [];

        // Semaines civiles (lundi) chevauchant la période.
        $weekMon = $start->copy()->startOfWeek(Carbon::MONDAY);
        while ($weekMon->lte($end)) {
            $weekSun = $weekMon->copy()->endOfWeek(Carbon::SUNDAY);
            $label = $weekMon->toDateString().'→'.$weekSun->toDateString();
            // La période couvre-t-elle INTÉGRALEMENT cette semaine réglementaire ?
            $covers = $start->lte($weekMon) && $end->gte($weekSun);
            $weekLines = $lines->filter(fn ($l) => $l->date->gte($weekMon) && $l->date->lte($weekSun));

            if (! $covers) {
                $out[] = $this->weeklyResult('weekly_hours_max', $market, $rules['weekly_hours_max'] ?? null, null, null, $label, self::STATUS_NOT_VERIFIABLE,
                    'Période insuffisante pour la semaine réglementaire (lun.–dim.) — contrôle non vérifiable.');
                $out[] = $this->weeklyResult('weekly_rest_min_hours', $market, $rules['weekly_rest_min_hours'] ?? null, null, null, $label, self::STATUS_NOT_VERIFIABLE,
                    'Période insuffisante pour la semaine réglementaire (lun.–dim.) — repos hebdomadaire non vérifiable.');
                $weekMon->addWeek();

                continue;
            }

            // Durée hebdomadaire maximale.
            $out[] = $this->weeklyMax($market, $rules['weekly_hours_max'] ?? null, $weekLines, $label, $isForfait, $workModel);
            // Repos hebdomadaire (conservateur : pass si ≥ seuil trouvé, sinon non vérifiable — jamais de faux positif).
            $out[] = $this->weeklyRest($market, $rules['weekly_rest_min_hours'] ?? null, $weekLines, $weekMon, $weekSun, $label);

            $weekMon->addWeek();
        }

        return $out;
    }

    private function weeklyMax(string $market, ?array $rule, Collection $weekLines, string $label, bool $isForfait, ?string $workModel): array
    {
        if ($isForfait) {
            return $this->weeklyResult('weekly_hours_max', $market, $rule, null, null, $label, self::STATUS_NOT_VERIFIABLE,
                "Régime « {$workModel} » : maximum hebdomadaire horaire non applicable tel quel ; régime non qualifié.");
        }
        $threshold = $this->minutesOf($rule);
        $observed = (int) $weekLines->sum('worked_minutes');
        if ($threshold === null) {
            return $this->weeklyResult('weekly_hours_max', $market, $rule, null, $observed, $label, self::STATUS_NOT_VERIFIABLE,
                'Durée hebdomadaire maximale non configurée pour ce Market.');
        }
        $status = $observed > $threshold ? self::STATUS_VIOLATION : self::STATUS_PASS;

        return $this->weeklyResult('weekly_hours_max', $market, $rule, $threshold, $observed, $label, $status,
            $status === self::STATUS_VIOLATION ? 'Durée hebdomadaire constatée supérieure au maximum applicable.' : 'Durée hebdomadaire dans la limite.');
    }

    private function weeklyRest(string $market, ?array $rule, Collection $weekLines, Carbon $weekMon, Carbon $weekSun, string $label): array
    {
        $threshold = $this->minutesOf($rule);
        if ($threshold === null) {
            return $this->weeklyResult('weekly_rest_min_hours', $market, $rule, null, null, $label, self::STATUS_NOT_VERIFIABLE,
                'Repos hebdomadaire minimal non configuré pour ce Market.');
        }
        // Intervalles de travail de la semaine (depuis les pointages réels).
        $intervals = [];
        foreach ($weekLines->where('worked_minutes', '>', 0) as $line) {
            $in = $this->firstClockIn($line);
            $outT = $this->lastClockOut($line);
            if ($in && $outT) {
                $intervals[] = [$in, $outT];
            }
        }
        if (empty($intervals)) {
            // Aucune donnée de pointage : on ne peut pas confirmer (ni conforme, ni violation).
            return $this->weeklyResult('weekly_rest_min_hours', $market, $rule, $threshold, null, $label, self::STATUS_NOT_VERIFIABLE,
                'Pointages insuffisants — repos hebdomadaire non vérifiable.');
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        // Plus long repos continu ENTRE périodes de travail dans la semaine.
        $maxRest = 0;
        for ($i = 0; $i < count($intervals) - 1; $i++) {
            $gap = $intervals[$i][1]->diffInMinutes($intervals[$i + 1][0], false);
            if ($gap > $maxRest) {
                $maxRest = $gap;
            }
        }
        // Conservateur : si un repos ≥ seuil est OBSERVÉ → conforme. Sinon NON VÉRIFIABLE (le repos peut
        // s'étendre sur la semaine adjacente, non disponible ici) — on ne produit JAMAIS un faux positif.
        if ($maxRest >= $threshold) {
            return $this->weeklyResult('weekly_rest_min_hours', $market, $rule, $threshold, $maxRest, $label, self::STATUS_PASS,
                'Repos hebdomadaire continu suffisant observé.');
        }

        return $this->weeklyResult('weekly_rest_min_hours', $market, $rule, $threshold, $maxRest, $label, self::STATUS_NOT_VERIFIABLE,
            'Aucun repos continu suffisant observé dans la semaine ; confirmation impossible sans la semaine adjacente.');
    }

    private function weeklyResult(string $ruleKey, string $market, ?array $rule, ?int $threshold, ?int $observed, string $label, string $status, string $message): array
    {
        return $this->result($ruleKey, $ruleKey === 'weekly_hours_max' ? 'weekly_max' : 'weekly_rest', $market, $rule, $threshold, $observed, $label, $status, $message,
            ['week' => $label, 'observed_minutes' => $observed, 'threshold_minutes' => $threshold]);
    }

    // ── Helpers pointages ──
    private function entries(object $line): array
    {
        return $line->source_snapshot['time_entries'] ?? [];
    }

    private function firstClockIn(object $line): ?Carbon
    {
        $ins = collect($this->entries($line))->pluck('clock_in')->filter()->map(fn ($t) => Carbon::parse($t));

        return $ins->isNotEmpty() ? $ins->min() : null;
    }

    private function lastClockOut(object $line): ?Carbon
    {
        $outs = collect($this->entries($line))->pluck('clock_out')->filter()->map(fn ($t) => Carbon::parse($t));

        return $outs->isNotEmpty() ? $outs->max() : null;
    }

    /** Valeur de règle en MINUTES (la règle est en heures) ; null si non résolue (« to_complete »). */
    private function minutesOf(?array $rule): ?int
    {
        $v = $rule['value'] ?? null;

        return $v === null ? null : (int) round(((float) $v) * 60);
    }
}
