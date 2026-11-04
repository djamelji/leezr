<?php

namespace App\Modules\Workforce\Security;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use Illuminate\Http\Request;

/**
 * ADR-634 (Workforce 2.0 / W-D) — contrôles d'accès RBAC fins pour Workforce.
 *
 * Centralise : (1) le droit de voir le salaire/compensation ; (2) le scope manager/self sur les
 * données d'un collaborateur (congés, pointages). Les décisions sont prises côté serveur, jamais
 * seulement masquées en Vue. `owner` conserve son bypass (via hasCompanyPermission).
 */
class WorkforceAccess
{
    /** L'utilisateur peut-il voir le salaire / la compensation ? */
    public static function canReadCompensation(Request $request): bool
    {
        return static::has($request, 'workforce.compensation_read');
    }

    /** Vérif générique d'une permission company (owner bypass). Réutilisable (P2-A home). */
    public static function can(Request $request, string $permission): bool
    {
        return static::has($request, $permission);
    }

    /** Scope manager d'un domaine : null = vue globale (hasViewAll), sinon soi + rapports directs. */
    public static function scopeIdsOrNull(Request $request, string $domain): ?array
    {
        return static::hasViewAll($request, $domain) ? null : static::visibleEmployeeIds($request, $domain);
    }

    /**
     * Peut-on lire les données RH (congés/pointages) d'un collaborateur donné ?
     * Autorisé si : capacité « voir tout » du domaine, OU c'est soi-même, OU c'est un rapport direct.
     *
     * @param  string  $domain  'leave' | 'time'
     */
    public static function canViewEmployeeData(Request $request, int $employeeId, string $domain): bool
    {
        if (static::hasViewAll($request, $domain)) {
            return true;
        }

        $self = static::requesterEmployee($request);
        if (! $self) {
            return false;
        }

        if ($self->id === $employeeId) {
            return true; // soi-même
        }

        // Rapport direct : l'employé ciblé a pour manager l'employé de l'utilisateur.
        return Employee::withoutCompanyScope()
            ->where('company_id', $self->company_id)
            ->where('id', $employeeId)
            ->where('manager_id', $self->id)
            ->exists();
    }

    /**
     * B3-checkpoint (ADR-699) — PÉRIMÈTRE d'APPROBATION/REFUS d'une feuille de temps.
     * Distingue le DROIT d'action (vérifié aussi en amont par le middleware) et le PÉRIMÈTRE de données :
     *  - `timesheet_approve` → Company (tout collaborateur, y compris soi — comportement existant préservé) ;
     *  - `timesheet_approve_team` (sans Company) → RAPPORTS DIRECTS UNIQUEMENT, JAMAIS soi-même
     *    (pas d'auto-approbation via la seule permission d'équipe).
     *  - aucune des deux → refusé. Owner bypass (via has()). Réutilise manager_id (1 niveau), pas d'ABAC.
     */
    public static function canApproveTimesheet(Request $request, int $employeeId): bool
    {
        // Périmètre Company : permission d'approbation Company explicite (ou owner).
        if (static::has($request, 'workforce.timesheet_approve')) {
            return true;
        }

        // Périmètre équipe : rapports directs seulement, et PAS soi-même.
        if (static::has($request, 'workforce.timesheet_approve_team')) {
            $self = static::requesterEmployee($request);
            if (! $self || $self->id === $employeeId) {
                return false;
            }

            return Employee::withoutCompanyScope()
                ->where('company_id', $self->company_id)
                ->where('id', $employeeId)
                ->where('manager_id', $self->id)
                ->exists();
        }

        return false;
    }

    /** Capacité « voir tout » pour un domaine donné (réutilise les permissions existantes). */
    public static function hasViewAll(Request $request, string $domain): bool
    {
        return match ($domain) {
            'leave' => static::has($request, 'workforce.leave_view_all') || static::has($request, 'workforce.leave_approve'),
            'time' => static::has($request, 'workforce.timesheet_approve') || static::has($request, 'workforce.time_manage'),
            // 'employee' = capacité RH de voir tous les collaborateurs (et leurs documents individuels).
            'employee' => static::has($request, 'workforce.view') && static::has($request, 'workforce.manage'),
            default => false,
        };
    }

    /** Les ids des collaborateurs visibles par l'utilisateur dans un domaine (soi + rapports directs). */
    public static function visibleEmployeeIds(Request $request, string $domain): array
    {
        $self = static::requesterEmployee($request);
        if (! $self) {
            return [];
        }
        $ids = [$self->id];
        $reports = Employee::withoutCompanyScope()
            ->where('company_id', $self->company_id)
            ->where('manager_id', $self->id)
            ->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $reports)));
    }

    /** L'enregistrement Employee de l'utilisateur courant dans la Company, s'il existe. */
    public static function requesterEmployee(Request $request): ?Employee
    {
        $companyId = $request->attributes->get('company_id');
        $userId = $request->user()?->id;
        if (! $companyId || ! $userId) {
            return null;
        }

        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
    }

    private static function has(Request $request, string $permission): bool
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        if (! $company instanceof Company || ! $user) {
            return false;
        }

        return $user->hasCompanyPermission($company, $permission);
    }
}
