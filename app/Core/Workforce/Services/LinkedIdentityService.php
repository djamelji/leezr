<?php

namespace App\Core\Workforce\Services;

use App\Core\Models\User;
use App\Core\Workforce\Employee;

/**
 * ADR-752 (Consolidation Company — Phase 0) — prévention des divergences d'identité pour les
 * personnes DÉJÀ RELIÉES (`employee.user_id` non nul). Propage le NOM entre la fiche RH
 * (`workforce_employees`) et le compte (`users`) de façon idempotente.
 *
 * Garde-fous doctrinaux :
 *   - AUCUN rapprochement automatique : on ne relie jamais deux personnes sur la seule base du
 *     nom/e-mail. Ce service n'agit QUE sur un lien `user_id` déjà établi explicitement.
 *   - Email de login NON touché : l'identité de connexion appartient au `User` (décision ADR-752) ;
 *     l'e-mail RH reste un champ de contact distinct. Seul le nom est synchronisé en Phase 0.
 *   - Idempotent et sans boucle : appelé une fois, explicitement, par la surface qui édite.
 */
class LinkedIdentityService
{
    /** Propage le nom d'un Employee vers son User lié (si lié). */
    public static function employeeToUser(Employee $employee): void
    {
        if (! $employee->user_id) {
            return;
        }
        $user = User::find($employee->user_id);
        if (! $user) {
            return;
        }

        $dirty = [];
        if (is_string($employee->first_name) && $employee->first_name !== '' && $user->first_name !== $employee->first_name) {
            $dirty['first_name'] = $employee->first_name;
        }
        if (is_string($employee->last_name) && $user->last_name !== $employee->last_name) {
            $dirty['last_name'] = $employee->last_name;
        }
        if ($dirty) {
            $user->forceFill($dirty)->save();
        }
    }

    /** Propage le nom d'un User vers le collaborateur RH lié dans cette company (si lié). */
    public static function userToEmployee(int $companyId, User $user): void
    {
        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();
        if (! $employee) {
            return;
        }

        $dirty = [];
        if (is_string($user->first_name) && $employee->first_name !== $user->first_name) {
            $dirty['first_name'] = $user->first_name;
        }
        if (is_string($user->last_name) && $employee->last_name !== $user->last_name) {
            $dirty['last_name'] = $user->last_name;
        }
        if ($dirty) {
            $employee->forceFill($dirty)->save();
        }
    }
}
