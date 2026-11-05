<?php

namespace App\Modules\Workforce\Services;

use App\Core\Models\Company;
use App\Core\Workforce\PlanningNotificationOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tampon de DURABILITÉ des notifications de planning (Smart Planning Lot 3 complément, ADR-717).
 *
 * enqueue*() écrit l'intention DANS la transaction du changement (atomique avec le commit du planning).
 * flush() consomme les lignes pending en réutilisant PlanningNotifier (pas de 2ᵉ moteur). Idempotent :
 * l'entity_key empêche tout doublon d'événement in-app même si le flush rejoue (post-commit + planifié).
 */
class PlanningOutbox
{
    /** Publication : groupe les shifts par salarié (from/to = plage de dates). */
    public function enqueuePublished(int $companyId, Collection $shifts): ?PlanningNotificationOutbox
    {
        $groups = [];
        foreach ($shifts->groupBy('employee_id') as $employeeId => $list) {
            $dates = collect($list)->map(fn ($s) => Carbon::parse($s->date)->toDateString())->sort()->values();
            $groups[] = [
                'employee_id' => (int) $employeeId,
                'from' => $dates->first(),
                'to' => $dates->last(),
                'count' => $list->count(),
            ];
        }

        return $this->enqueue($companyId, 'workforce.planning_published', false, $groups, null);
    }

    /** Modification d'un planning publié : un groupe par salarié concerné, daté. */
    public function enqueueChanged(int $companyId, array $employeeIds, ?Carbon $date, ?string $eventRef): ?PlanningNotificationOutbox
    {
        $day = ($date ?? now())->toDateString();
        $ids = array_values(array_unique(array_filter($employeeIds)));
        if (! $ids) {
            return null;
        }
        $groups = array_map(fn ($id) => [
            'employee_id' => (int) $id, 'from' => $day, 'to' => $day, 'count' => 1,
        ], $ids);

        return $this->enqueue($companyId, 'workforce.planning_changed', true, $groups, $eventRef);
    }

    private function enqueue(int $companyId, string $topicKey, bool $isChange, array $groups, ?string $eventRef): ?PlanningNotificationOutbox
    {
        if (! $groups) {
            return null;
        }

        return PlanningNotificationOutbox::create([
            'company_id' => $companyId,
            'topic_key' => $topicKey,
            'is_change' => $isChange,
            'event_ref' => $eventRef,
            'groups' => $groups,
            'status' => PlanningNotificationOutbox::STATUS_PENDING,
        ]);
    }

    /**
     * Consomme les intentions pending (post-commit immédiat ET filet planifié). Chaque ligne est traitée
     * dans sa propre transaction avec verrou → pas de double-traitement concurrent ; l'entity_key garantit
     * l'absence de doublon d'événement in-app. Les échecs d'envoi marquent la ligne `failed` (retentée).
     *
     * @return int  nombre de lignes traitées avec succès
     */
    public function flush(int $limit = 200): int
    {
        $ids = PlanningNotificationOutbox::pending()
            ->orderBy('id')->limit($limit)->pluck('id');

        $notifier = app(PlanningNotifier::class);
        $sent = 0;

        foreach ($ids as $id) {
            $processed = DB::transaction(function () use ($id, $notifier) {
                /** @var PlanningNotificationOutbox|null $row */
                $row = PlanningNotificationOutbox::whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->status !== PlanningNotificationOutbox::STATUS_PENDING) {
                    return false; // déjà traité par un autre flush concurrent
                }

                $company = Company::find($row->company_id);
                if (! $company) {
                    $row->update(['status' => PlanningNotificationOutbox::STATUS_FAILED, 'last_error' => 'company not found', 'attempts' => $row->attempts + 1]);

                    return false;
                }

                try {
                    $notifier->sendGroups($company, $row->groups, $row->topic_key, (bool) $row->is_change, $row->event_ref);
                    $row->update([
                        'status' => PlanningNotificationOutbox::STATUS_SENT,
                        'attempts' => $row->attempts + 1,
                        'processed_at' => now(),
                    ]);

                    return true;
                } catch (\Throwable $e) {
                    report($e);
                    $row->update([
                        'status' => PlanningNotificationOutbox::STATUS_FAILED,
                        'attempts' => $row->attempts + 1,
                        'last_error' => substr($e->getMessage(), 0, 500),
                    ]);

                    return false;
                }
            });

            if ($processed) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Re-tente les lignes failed (remises en pending) — utilisé par la commande planifiée. */
    public function retryFailed(int $limit = 100): int
    {
        return PlanningNotificationOutbox::where('status', PlanningNotificationOutbox::STATUS_FAILED)
            ->where('attempts', '<', 5)
            ->limit($limit)
            ->update(['status' => PlanningNotificationOutbox::STATUS_PENDING]);
    }
}
