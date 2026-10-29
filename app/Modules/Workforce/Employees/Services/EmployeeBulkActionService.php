<?php

namespace App\Modules\Workforce\Employees\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;

/**
 * W-G2 (ADR-642) — actions groupées SÛRES sur les collaborateurs : changement de département, de
 * poste ou de responsable. Jamais de contrat, rémunération, rôle applicatif, ni sortie/clôture.
 *
 * Garde-fous (backend = source de vérité) : tenant, scope manager (si l'appelant n'a pas la vue
 * globale), existence, cible de la même Company, pas de cycle hiérarchique, collaborateurs
 * inéligibles reportés par id (jamais d'autorisation déduite de la seule visibilité de liste).
 */
class EmployeeBulkActionService
{
    public const ACTIONS = ['set_department', 'set_job_role', 'set_manager'];
    public const MAX_TARGETS = 500;

    /**
     * Prévisualise l'action : éligibles, inéligibles (avec motif), libellé de la cible.
     *
     * @param  array<int>  $employeeIds
     * @param  array<int>|null  $visibleIds  scope manager (null = vue globale RH)
     */
    public function preview(int $companyId, string $action, array $employeeIds, ?int $targetId, ?array $visibleIds): array
    {
        $this->assertAction($action);

        $target = $this->resolveTarget($companyId, $action, $targetId);

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if (count($employeeIds) > self::MAX_TARGETS) {
            throw new \DomainException('Trop de collaborateurs sélectionnés (max '.self::MAX_TARGETS.').');
        }

        $employees = Employee::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)->get()->keyBy('id');

        $eligible = [];
        $ineligible = [];

        foreach ($employeeIds as $id) {
            $emp = $employees->get($id);
            $reason = $this->ineligibilityReason($emp, $id, $action, $targetId, $visibleIds, $companyId);
            if ($reason !== null) {
                $ineligible[] = ['id' => $id, 'name' => $emp ? trim($emp->first_name.' '.$emp->last_name) : null, 'reason' => $reason];
            } else {
                $eligible[] = ['id' => $id, 'name' => trim($emp->first_name.' '.$emp->last_name)];
            }
        }

        return [
            'action' => $action,
            'target' => $target,
            'total' => count($employeeIds),
            'eligible' => $eligible,
            'ineligible' => $ineligible,
        ];
    }

    /**
     * Applique l'action aux collaborateurs éligibles (transaction, audit, bilan par collaborateur).
     *
     * @param  array<int>  $employeeIds
     * @param  array<int>|null  $visibleIds
     */
    public function apply(int $companyId, string $action, array $employeeIds, ?int $targetId, ?array $visibleIds, int $actorId): array
    {
        $preview = $this->preview($companyId, $action, $employeeIds, $targetId, $visibleIds);

        $column = match ($action) {
            'set_department' => 'department_id',
            'set_job_role' => 'job_role_id',
            'set_manager' => 'manager_id',
        };

        $result = ['updated' => 0, 'skipped' => count($preview['ineligible']), 'rows' => []];

        DB::transaction(function () use ($preview, $column, $targetId, $companyId, &$result) {
            foreach ($preview['eligible'] as $e) {
                $emp = Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($e['id']);
                if (! $emp) {
                    $result['skipped']++;
                    $result['rows'][] = ['id' => $e['id'], 'status' => 'failed', 'reason' => 'introuvable'];

                    continue;
                }
                $emp->update([$column => $targetId]);
                $result['updated']++;
                $result['rows'][] = ['id' => $e['id'], 'status' => 'updated'];
            }
        });

        foreach ($preview['ineligible'] as $i) {
            $result['rows'][] = ['id' => $i['id'], 'status' => 'skipped', 'reason' => $i['reason']];
        }

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'employee.bulk_updated',
            targetType: 'employee',
            targetId: 'batch',
            options: ['metadata' => [
                'category' => 'workforce.employee',
                'description' => "Action groupée {$action} → cible {$targetId} : {$result['updated']} mis à jour, {$result['skipped']} ignorés",
            ]],
        );

        return $result;
    }

    private function assertAction(string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \DomainException("Action groupée non supportée : {$action}");
        }
    }

    /** Résout et valide la cible (département/poste/responsable) dans la même Company. */
    private function resolveTarget(int $companyId, string $action, ?int $targetId): ?array
    {
        if ($targetId === null) {
            throw new \DomainException('Cible manquante.');
        }

        $model = match ($action) {
            'set_department' => Department::withoutGlobalScopes()->where('company_id', $companyId)->find($targetId),
            'set_job_role' => JobRole::withoutGlobalScopes()->where('company_id', $companyId)->find($targetId),
            'set_manager' => Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($targetId),
        };

        if (! $model) {
            throw new \DomainException('Cible invalide ou appartenant à une autre entreprise.');
        }

        return match ($action) {
            'set_department' => ['id' => $model->id, 'label' => $model->name],
            'set_job_role' => ['id' => $model->id, 'label' => $model->title],
            'set_manager' => ['id' => $model->id, 'label' => trim($model->first_name.' '.$model->last_name)],
        };
    }

    private function ineligibilityReason(?Employee $emp, int $id, string $action, ?int $targetId, ?array $visibleIds, int $companyId): ?string
    {
        if (! $emp) {
            return 'Collaborateur introuvable';
        }
        if ($visibleIds !== null && ! in_array($id, $visibleIds, true)) {
            return 'Hors de votre périmètre';
        }
        if ($emp->status === Employee::STATUS_TERMINATED) {
            return 'Collaborateur sorti';
        }
        if ($action === 'set_manager') {
            if ($targetId === $id) {
                return 'Un collaborateur ne peut pas être son propre responsable';
            }
            if ($this->wouldCreateCycle($companyId, $id, $targetId)) {
                return 'Relation hiérarchique circulaire';
            }
        }

        return null;
    }

    /** Vrai si définir `$managerId` comme responsable de `$employeeId` crée un cycle. */
    private function wouldCreateCycle(int $companyId, int $employeeId, int $managerId): bool
    {
        $current = $managerId;
        $guard = 0;
        while ($current !== null && $guard++ < 1000) {
            if ($current === $employeeId) {
                return true;
            }
            $current = Employee::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('id', $current)->value('manager_id');
        }

        return false;
    }
}
