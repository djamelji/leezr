<?php

namespace Database\Seeders;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Database\Seeder;

/**
 * Creates demo workforce data for the demo company.
 * Called from DevSeeder — safe to run multiple times (updateOrCreate).
 */
class WorkforceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'leezr-logistics')->first();
        if (! $company) {
            return;
        }

        // Seed leave types for this company
        LeaveTypeSeeder::seedForCompany($company->id, $company->market_key ?? 'FR');

        // ─── Demo employees ────────────────────────────────────
        $users = User::whereHas('memberships', fn ($q) => $q->where('company_id', $company->id))->get();

        foreach ($users as $index => $user) {
            $employee = Employee::withoutCompanyScope()->updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $user->id],
                [
                    'employee_number' => 'EMP-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'hire_date' => now()->subMonths(6 + $index * 3)->startOfMonth(),
                    'status' => 'active',
                ],
            );

            // ─── Employment contract ─────────────────────────────
            $contract = EmploymentContract::withoutCompanyScope()->updateOrCreate(
                ['company_id' => $company->id, 'employee_id' => $employee->id, 'contract_type' => 'cdi'],
                [
                    'start_date' => $employee->hire_date,
                    'job_title' => match ($index) {
                        0 => 'Directeur Général',
                        1 => 'Responsable Logistique',
                        default => 'Chauffeur-Livreur',
                    },
                    'department' => match ($index) {
                        0 => 'Direction',
                        1 => 'Logistique',
                        default => 'Transport',
                    },
                    'work_schedule_type' => 'full_time',
                    'weekly_hours_hundredths' => 3500,
                    'status' => 'active',
                ],
            );

            // ─── Compensation plan ───────────────────────────────
            CompensationPlan::withoutCompanyScope()->updateOrCreate(
                ['company_id' => $company->id, 'employment_contract_id' => $contract->id],
                [
                    'base_salary_cents' => match ($index) {
                        0 => 650000,   // 6500€/mois
                        1 => 380000,   // 3800€/mois
                        default => 220000,  // 2200€/mois
                    },
                    'currency' => 'EUR',
                    'pay_frequency' => 'monthly',
                    'effective_date' => $contract->start_date,
                ],
            );
        }
    }
}
