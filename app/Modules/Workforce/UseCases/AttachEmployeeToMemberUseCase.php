<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Data\CreateEmployeeData;
use Illuminate\Validation\ValidationException;

/**
 * ADR-768 (M7 LOT 2) — the EXPLICIT bridge between an account (User member of the company) and an
 * HR record (Employee). Two operations, both decided by a human on a precise pair of ids :
 *
 *  - `createForMember` : give an HR record to an existing member (never invented automatically) ;
 *  - `linkAccount`     : attach an existing HR record (without account) to an existing member.
 *
 * Never a rapprochement by name or e-mail (ADR-752). One account ↔ at most one HR record per
 * company, and the account must be a member of THIS company. The identity of the account (global
 * User) is never written here.
 */
class AttachEmployeeToMemberUseCase
{
    public function __construct(private readonly CreateEmployeeUseCase $create, private readonly AuditLogger $audit)
    {
    }

    /** @param array{hire_date: string, employee_number?: ?string, department_id?: ?int, job_role_id?: ?int, manager_id?: ?int} $data */
    public function createForMember(Company $company, int $userId, array $data, User $actor): Employee
    {
        $user = $this->memberOrFail($company, $userId);
        $this->assertNoHrRecord($company, $user->id);

        $employee = $this->create->execute(new CreateEmployeeData(
            companyId: $company->id,
            firstName: (string) $user->first_name,
            lastName: (string) $user->last_name,
            hireDate: $data['hire_date'],
            userId: $user->id,
            employeeNumber: $data['employee_number'] ?? null,
            email: $user->email,
            departmentId: $data['department_id'] ?? null,
            jobRoleId: $data['job_role_id'] ?? null,
            managerId: $data['manager_id'] ?? null,
        ));

        $this->log($company, $employee, $actor, 'employee.created_for_member');

        return $employee;
    }

    public function linkAccount(Company $company, Employee $employee, int $userId, User $actor): Employee
    {
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['user_id' => 'Cette fiche est déjà rattachée à un compte.']);
        }
        $user = $this->memberOrFail($company, $userId);
        $this->assertNoHrRecord($company, $user->id);

        $employee->user_id = $user->id;
        $employee->save();
        \App\Core\Documents\Services\PersonDocumentContinuity::adopt($company, $user); // ADR-769 : les pièces de la fiche suivent la personne
        $this->log($company, $employee, $actor, 'employee.account_linked');

        return $employee;
    }

    /** Members of the company that have no HR record yet (candidates for either operation). */
    public function candidates(Company $company): array
    {
        $taken = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('user_id')->pluck('user_id')->all();

        return Membership::where('company_id', $company->id)->whereNotIn('user_id', $taken)
            ->with('user:id,first_name,last_name,email')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->map(fn (Membership $m) => [
                'user_id' => $m->user_id,
                'name' => trim($m->user->first_name.' '.$m->user->last_name) ?: $m->user->email,
                'email' => $m->user->email,
            ])->values()->all();
    }

    private function memberOrFail(Company $company, int $userId): User
    {
        $membership = Membership::where('company_id', $company->id)->where('user_id', $userId)->with('user')->first();
        if (! $membership || ! $membership->user) {
            throw ValidationException::withMessages(['user_id' => "Ce compte n'est pas membre de cette entreprise."]);
        }

        return $membership->user;
    }

    private function assertNoHrRecord(Company $company, int $userId): void
    {
        if (Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Ce compte est déjà rattaché à une fiche collaborateur.']);
        }
    }

    private function log(Company $company, Employee $employee, User $actor, string $action): void
    {
        $this->audit->logCompany($company->id, $action, 'employee', (string) $employee->id, [
            'actorId' => $actor->id, 'actorType' => 'user', 'metadata' => ['user_id' => $employee->user_id],
        ]);
    }
}
