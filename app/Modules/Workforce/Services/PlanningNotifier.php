<?php

namespace App\Modules\Workforce\Services;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Notifications\NotificationEvent;
use App\Core\Workforce\Employee;
use App\Notifications\Workforce\PlanningPublishedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Notifications de planning publié / modifié (Smart Planning Lot 2, ADR-715).
 *
 * Réutilise NotificationDispatcher (in-app NotificationEvent + email via PlanningPublishedNotification).
 * Pas de 2ᵉ moteur. Appelé par les contrôleurs APRÈS le commit des UseCases → jamais de notif pour une opération échouée.
 * Idempotent : un entity_key stable par salarié+période évite les doublons (retry / re-publication).
 * Résout le compte utilisateur du salarié ; les salariés sans user sont ignorés (in-app impossible).
 */
class PlanningNotifier
{
    /** Publication : notifie les salariés concernés après commit. */
    public function notifyPublished(int $companyId, Collection $shifts): void
    {
        $company = Company::find($companyId);
        if (! $company) {
            return;
        }
        $byEmployee = $shifts->groupBy('employee_id');
        $this->dispatch($company, $byEmployee, 'workforce.planning_published', false);
    }

    /**
     * Modification d'un planning publié : notifie uniquement les salariés réellement concernés.
     *
     * $eventRef = IDENTIFIANT STABLE de l'ÉVÉNEMENT métier (ex. "activity:{id}:{updatedAtEpoch}"). Il sépare :
     *  - l'id d'événement métier ($eventRef) → deux modifications distinctes le même jour = deux events ;
     *  - l'id de regroupement (salarié+événement) via entity_key → dédup au REJEU exact ;
     *  - l'id de tentative = la requête HTTP (une par appel). Sans $eventRef, on retombe sur la date (dédup/jour).
     */
    public function notifyChanged(int $companyId, array $employeeIds, ?Carbon $date = null, ?string $eventRef = null): void
    {
        $company = Company::find($companyId);
        if (! $company) {
            return;
        }
        $ids = array_values(array_unique(array_filter($employeeIds)));
        if (! $ids) {
            return;
        }
        $byEmployee = collect($ids)->mapWithKeys(fn ($id) => [$id => collect([(object) ['date' => $date ?? now()]])]);
        $this->dispatch($company, $byEmployee, 'workforce.planning_changed', true, $eventRef);
    }

    /**
     * Envoi depuis des GROUPES normalisés [{employee_id, from, to, count}] — utilisé par l'outbox (ADR-717).
     * Réutilise exactement la même logique idempotente (entity_key) que les chemins directs.
     *
     * @param  array<int, array{employee_id:int, from:string, to:string, count:int}>  $groups
     */
    public function sendGroups(Company $company, array $groups, string $topicKey, bool $isChange, ?string $eventRef = null): void
    {
        foreach ($groups as $g) {
            $this->dispatchOne($company, [
                'employee_id' => (int) $g['employee_id'],
                'from' => $g['from'],
                'to' => $g['to'],
                'count' => (int) ($g['count'] ?? 1),
                'event_ref' => $eventRef,
            ], $topicKey, $isChange);
        }
    }

    /**
     * Les notifications sont TOUJOURS déclenchées par les contrôleurs APRÈS le commit des UseCases
     * (publication / révision) → dispatch immédiat, pas de notif pour une opération échouée (qui lève
     * une exception avant d'arriver ici).
     */
    private function dispatch(Company $company, Collection $byEmployee, string $topicKey, bool $isChange, ?string $eventRef = null): void
    {
        foreach ($byEmployee as $employeeId => $list) {
            $dates = collect($list)->map(fn ($s) => Carbon::parse($s->date)->toDateString())->sort()->values();
            $this->dispatchOne($company, [
                'employee_id' => (int) $employeeId,
                'from' => $dates->first(),
                'to' => $dates->last(),
                'count' => $list->count(),
                'event_ref' => $eventRef,
            ], $topicKey, $isChange);
        }
    }

    private function dispatchOne(Company $company, array $p, string $topicKey, bool $isChange): void
    {
        $employee = Employee::withoutGlobalScopes()
            ->where('id', $p['employee_id'])->where('company_id', $company->id)
            ->with('user')->first();

        $user = $employee?->user;
        if (! $user instanceof User) {
            return; // pas de compte → in-app impossible (limitation documentée)
        }

        $periodLabel = $p['from'] === $p['to'] ? $p['from'] : "{$p['from']} → {$p['to']}";
        // entity_key = topic:user:(événement métier | période). L'event_ref discrimine deux changements
        // distincts le même jour ; absent → clé par période (dédup/jour, cas publication).
        $discriminator = $p['event_ref'] ?? $p['from'];
        $entityKey = str_replace('.', '_', $topicKey).":{$user->id}:{$discriminator}";

        // Idempotence : si une notif non lue identique existe déjà, on ne duplique pas.
        $exists = NotificationEvent::where('topic_key', $topicKey)
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->id)
            ->where('entity_key', $entityKey)
            ->whereNull('read_at')
            ->exists();
        if ($exists) {
            return;
        }

        $url = rtrim(config('app.url'), '/').'/company/workforce/me';

        // Résilience (§5) : un échec d'envoi (SMTP/provider/queue) ne doit JAMAIS casser la publication.
        // La traçabilité (EmailLog, logs) est assurée par l'infra ; ici on isole l'erreur.
        try {
            NotificationDispatcher::send(
                topicKey: $topicKey,
                recipients: [$user],
                payload: [
                    'period' => $periodLabel,
                    'count' => $p['count'],
                    'link' => '/company/workforce/me',
                ],
                company: $company,
                mailNotification: new PlanningPublishedNotification($periodLabel, (int) $p['count'], $url, $isChange),
                entityKey: $entityKey,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
