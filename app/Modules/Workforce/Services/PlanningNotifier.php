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

    /** Modification d'un planning publié : notifie uniquement les salariés réellement concernés. */
    public function notifyChanged(int $companyId, array $employeeIds, ?Carbon $date = null): void
    {
        $company = Company::find($companyId);
        if (! $company) {
            return;
        }
        $ids = array_values(array_unique(array_filter($employeeIds)));
        if (! $ids) {
            return;
        }
        // Regroupe par salarié avec la date impactée (période = jour).
        $byEmployee = collect($ids)->mapWithKeys(fn ($id) => [$id => collect([(object) ['date' => $date ?? now()]])]);
        $this->dispatch($company, $byEmployee, 'workforce.planning_changed', true);
    }

    /**
     * Les notifications sont TOUJOURS déclenchées par les contrôleurs APRÈS le commit des UseCases
     * (publication / révision) → dispatch immédiat, pas de notif pour une opération échouée (qui lève
     * une exception avant d'arriver ici).
     */
    private function dispatch(Company $company, Collection $byEmployee, string $topicKey, bool $isChange): void
    {
        foreach ($byEmployee as $employeeId => $list) {
            $dates = collect($list)->map(fn ($s) => Carbon::parse($s->date)->toDateString())->sort()->values();
            $this->dispatchOne($company, [
                'employee_id' => (int) $employeeId,
                'from' => $dates->first(),
                'to' => $dates->last(),
                'count' => $list->count(),
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
        $entityKey = str_replace('.', '_', $topicKey).":{$user->id}:{$p['from']}";

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
