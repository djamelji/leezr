<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentSubject;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;

/**
 * ADR-759 (DMS M2) — résout l'ensemble des SUJETS documentaires EXPLICITES d'une personne, pour
 * une projection documentaire UNIFIÉE (continuité des dossiers). Un collaborateur peut cumuler des
 * documents sous `employee:{id}` (déposés sans compte) ET `user:{id}` (après activation du compte).
 *
 * Rapprochement EXPLICITE uniquement (liens `user_id` / `employee.user_id`) — JAMAIS par nom/e-mail.
 * Les fichiers ne sont JAMAIS déplacés : on LIT sur tous les sujets, on ÉCRIT sur le sujet du
 * document existant (sinon le sujet canonique). Aucune création de compte artificiel.
 */
class PersonSubjectResolver
{
    /**
     * @return array{subjects: list<DocumentSubject>, canonical: ?DocumentSubject, user: ?User, employee: ?Employee}
     */
    public static function resolve(Company $company, ?User $user, ?Employee $employee): array
    {
        $subjects = [];

        // Le sujet employee d'abord (antériorité possible : docs déposés avant le compte).
        if ($employee !== null) {
            $subjects[] = DocumentSubject::employee((int) $employee->id);
        }
        if ($user !== null) {
            $subjects[] = DocumentSubject::user((int) $user->id);
        }

        // Canonique pour les NOUVELLES écritures : le compte s'il existe, sinon l'employé.
        $canonical = $user !== null
            ? DocumentSubject::user((int) $user->id)
            : ($employee !== null ? DocumentSubject::employee((int) $employee->id) : null);

        return [
            'subjects' => $subjects,
            'canonical' => $canonical,
            'user' => $user,
            'employee' => $employee,
        ];
    }

    /**
     * Résout le couple (User, Employee) d'une personne à partir d'un personRef déjà parsé.
     * Vérifie l'appartenance tenant. Aucun rapprochement implicite.
     *
     * @return array{user: ?User, employee: ?Employee}
     */
    public static function identify(Company $company, string $refType, int $refId): array
    {
        $user = null;
        $employee = null;

        if ($refType === DocumentSubject::TYPE_USER) {
            $company->memberships()->where('user_id', $refId)->firstOrFail();
            $user = User::findOrFail($refId);
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->first();
        } else {
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($refId);
            $user = $employee->user_id ? User::find($employee->user_id) : null;
        }

        return ['user' => $user, 'employee' => $employee];
    }
}
