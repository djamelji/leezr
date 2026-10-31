<?php

namespace App\Modules\Workforce\Employees\Services;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Billing\CompanyEntitlements;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * W-HR-UX-3 (ADR-668) — accès à l'espace collaborateur DEPUIS la fiche RH, sans écran Members séparé.
 *
 * RÉUTILISE strictement le mécanisme d'invitation existant (User sans mot de passe → notification de
 * définition de mot de passe ; Membership avec un rôle Company). N'invente PAS un second système de comptes.
 * Lie correctement User ↔ Membership ↔ Employee (employee.user_id). Idempotent, anti-doublon, jamais de
 * privilège administratif automatique, respect tenant/RBAC/entitlements.
 *
 * États d'accès réels (pas de confusion invitation ≠ activation) :
 *   - none      : le collaborateur n'a aucun compte lié.
 *   - invited   : compte créé, mot de passe JAMAIS défini (invitation en attente).
 *   - active    : mot de passe défini (compte activé) mais AUCUNE connexion encore enregistrée.
 *   - connected : première connexion effectuée (W-HR-UX-4 §4 — last_login_at, posé par l'event Login,
 *                 jamais déduit du seul mot de passe défini).
 * (La « suspension » n'est pas un état first-class du modèle actuel → non revendiquée ; documentée comme limite.)
 */
class EmployeeAccessService
{
    /** État d'accès courant du collaborateur + options (rôles non-admin proposables). */
    public function status(Company $company, Employee $employee): array
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        $membership = $user ? Membership::where('company_id', $company->id)->where('user_id', $user->id)->first() : null;

        $state = 'none';
        if ($user && $membership) {
            // none → invited (mot de passe non défini) → active (défini) → connected (1re connexion effectuée).
            if (! $user->password_set_at) {
                $state = 'invited';
            } else {
                $state = $user->last_login_at ? 'connected' : 'active';
            }
        } elseif ($user && ! $membership) {
            // Compte lié mais sans membership dans CETTE company (rare) → à ré-associer.
            $state = 'account_without_membership';
        }

        return [
            'state' => $state,
            'email' => $employee->email,
            'has_email' => ! empty($employee->email),
            'user_status' => $user?->status,            // 'invited' | 'active' (attribut User)
            'invited_at' => $membership?->created_at?->toIso8601String(),
            'role' => $membership?->company_role_id,
            'assignable_roles' => CompanyRole::where('company_id', $company->id)
                ->where('is_administrative', false)     // jamais de privilège admin par défaut
                ->get(['id', 'name', 'key'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'can_resend' => $state === 'invited',
        ];
    }

    /**
     * Donne/associe l'accès à l'espace collaborateur. Idempotent : si déjà lié, ne recrée rien.
     *
     * @throws ValidationException
     */
    public function grant(Company $company, Employee $employee, string $email, ?int $companyRoleId, int $actorId): array
    {
        $email = trim(mb_strtolower($email));
        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'Une adresse e-mail est requise pour donner accès.']);
        }

        // Déjà lié + membership → rien à faire (idempotent).
        if ($employee->user_id) {
            $existing = Membership::where('company_id', $company->id)->where('user_id', $employee->user_id)->first();
            if ($existing) {
                return $this->status($company, $employee->refresh());
            }
        }

        // Rôle : non-administratif uniquement (pas de privilège admin auto). Défaut = 1er rôle non-admin.
        $role = $companyRoleId
            ? CompanyRole::where('company_id', $company->id)->where('id', $companyRoleId)->first()
            : CompanyRole::where('company_id', $company->id)->where('is_administrative', false)->orderBy('id')->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => 'Aucun rôle collaborateur disponible pour cette entreprise.']);
        }
        if ($role->is_administrative) {
            throw ValidationException::withMessages(['role' => 'Un rôle administratif ne peut pas être attribué via l\'accès collaborateur.']);
        }

        $user = User::where('email', $email)->first();
        $isNewUser = false;

        if (! $user) {
            // Limite de membres (entitlements) — seulement si un NOUVEAU membre est ajouté.
            $this->assertMemberLimit($company);
            $user = User::create([
                'first_name' => $employee->first_name ?: explode('@', $email)[0],
                'last_name' => $employee->last_name ?: '',
                'email' => $email,
                'password' => null,
            ]);
            $isNewUser = true;
        }

        // Anti-doublon de liaison : un compte déjà rattaché à un AUTRE collaborateur de cette company ne doit
        // pas être ré-attribué silencieusement.
        $otherEmployee = Employee::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('user_id', $user->id)->where('id', '!=', $employee->id)->first();
        if ($otherEmployee) {
            throw ValidationException::withMessages(['email' => 'Ce compte est déjà rattaché à un autre collaborateur.']);
        }

        $membership = Membership::where('company_id', $company->id)->where('user_id', $user->id)->first();
        if (! $membership) {
            if (! $isNewUser) {
                $this->assertMemberLimit($company);
            }
            $membership = $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'user',
                'company_role_id' => $role->id,
            ]);
        }

        // Lie le collaborateur au compte.
        $employee->user_id = $user->id;
        $employee->save();

        // Invitation (définition du mot de passe) uniquement pour un nouveau compte sans mot de passe.
        if ($isNewUser && ! $user->password_set_at) {
            $token = Password::broker('users')->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $company->id, \App\Core\Audit\AuditAction::MEMBER_ADDED,
            'employee_access', (string) $employee->id,
            ['actorId' => $actorId, 'actorType' => 'user', 'metadata' => ['action' => 'grant_portal_access', 'user_id' => $user->id, 'role_id' => $role->id]],
        );

        return $this->status($company, $employee->refresh());
    }

    /** Relance l'invitation (uniquement si compte invité, mot de passe non défini). */
    public function resendInvitation(Company $company, Employee $employee): array
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        if (! $user || $user->password_set_at) {
            throw ValidationException::withMessages(['access' => 'Aucune invitation en attente pour ce collaborateur.']);
        }
        $token = Password::broker('users')->createToken($user);
        $user->sendPasswordResetNotification($token);

        return $this->status($company, $employee);
    }

    private function assertMemberLimit(Company $company): void
    {
        $limit = CompanyEntitlements::memberLimit($company);
        if ($limit !== null && $company->memberships()->count() >= $limit) {
            throw ValidationException::withMessages(['access' => "Limite de membres atteinte ({$limit}). Impossible d'ajouter un accès."]);
        }
    }
}
