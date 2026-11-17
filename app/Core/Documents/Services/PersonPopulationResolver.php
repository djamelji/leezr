<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentSubject;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Workforce\Employee;
use Illuminate\Support\Collection;

/**
 * ADR-761 (DMS M4) — population de référence d'une obligation documentaire, DÉDUPLIQUÉE et
 * indépendante de la présence d'un compte applicatif. Réutilisé par l'agrégat de conformité (P0)
 * ET le ciblage des campagnes.
 *
 * Règles :
 *  - un Employee (avec ou sans compte) est compté : sujet canonique = `user:{id}` s'il a un compte,
 *    sinon `employee:{id}` ;
 *  - un User/Membership sans Employee est compté comme `user:{id}` ;
 *  - déduplication par sujet canonique → jamais de doublon Employee + User relié ;
 *  - les Employees TERMINÉS sont exclus par défaut (politique configurable) ;
 *  - rapprochement EXPLICITE uniquement (employee.user_id), jamais par nom/e-mail.
 *
 * @return Collection<int, array{subject:DocumentSubject,user_id:?int,employee_id:?int,department_id:?int,status:?string,role_key:?string,role_name:?string,account_less:bool}>
 */
class PersonPopulationResolver
{
    public static function forCompany(Company $company, bool $includeTerminated = false): Collection
    {
        // ADR-771 (tenant strict) : la population d'une entreprise se résout DANS son contexte, quel
        // que soit l'appelant (HTTP, job de campagne, commande planifiée).
        if (\App\Core\Tenancy\CompanyContext::id() !== (int) $company->id) {
            return \App\Core\Tenancy\CompanyContext::runFor($company, fn () => self::forCompany($company, $includeTerminated));
        }

        // ADR-770 : la population canonique suppose des lignes rattachées au sujet canonique.
        PersonDocumentContinuity::reconcile($company);

        $persons = [];

        // 1. Employees (RH) — inclut les sujets SANS compte. Si Workforce est inactif, la table est
        // vide pour cette société (aucun employé) → requête sans effet. On ne dépend donc pas du gate.
        Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when(! $includeTerminated, fn ($q) => $q->where('status', '!=', Employee::STATUS_TERMINATED))
            ->operational() // ADR-796.1 : une recrue non intégrée n'entre ni en conformité ni en campagne
            ->get()
            ->each(function (Employee $e) use (&$persons) {
                    $subject = $e->user_id ? DocumentSubject::user((int) $e->user_id) : DocumentSubject::employee((int) $e->id);
                    $persons[(string) $subject] = [
                        'subject' => $subject,
                        'user_id' => $e->user_id ? (int) $e->user_id : null,
                        'employee_id' => (int) $e->id,
                        'job_role_id' => $e->job_role_id ? (int) $e->job_role_id : null, // ADR-818 : autorité « poste »
                        'department_id' => $e->department_id ? (int) $e->department_id : null,
                        'status' => $e->status,
                        'role_key' => null,
                        'role_name' => null,
                        'account_less' => $e->user_id === null,
                    ];
            });

        // 2. Memberships (comptes applicatifs) non déjà couverts par un Employee.
        Membership::where('company_id', $company->id)
            ->with('companyRole:id,key,name')
            ->get()
            ->each(function (Membership $m) use (&$persons) {
                $key = (string) DocumentSubject::user((int) $m->user_id);
                if (! isset($persons[$key])) {
                    $persons[$key] = [
                        'subject' => DocumentSubject::user((int) $m->user_id),
                        'user_id' => (int) $m->user_id,
                        'employee_id' => null,
                        'department_id' => null,
                        'status' => null,
                        'role_key' => $m->companyRole?->key,
                        'role_name' => $m->companyRole?->name,
                        'account_less' => false,
                    ];
                } else {
                    // Enrichit le rôle sur une personne déjà résolue via Employee.
                    $persons[$key]['role_key'] = $m->companyRole?->key;
                    $persons[$key]['role_name'] = $m->companyRole?->name;
                }
            });

        return collect(array_values($persons));
    }
}
