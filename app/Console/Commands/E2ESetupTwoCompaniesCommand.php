<?php

namespace App\Console\Commands;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * E2E fixture (LOCAL/TESTING only): prove ONE Workforce installation serves TWO companies with
 * different trades (JobDomains) and conventional configs, isolated from each other.
 *
 *  - Company A = the existing "Leezr Logistics" (owner@leezr.test), JobDomain logistique,
 *    break TARGET = 30 min.
 *  - Company B = a fresh "Beta Services" company, a DIFFERENT JobDomain, its own owner-employee
 *    (ownerb@leezr.test), a distinct Convention Collective, break TARGET = 45 min.
 *
 * Each company's break rule is resolved server-side per-company; neither can read the other's
 * config or HR data (tenant isolation). NOT for production.
 */
class E2ESetupTwoCompaniesCommand extends Command
{
    protected $signature = 'e2e:setup-two-companies {--a-minutes=30} {--b-minutes=45}';

    protected $description = 'Set up two isolated Workforce companies (different JobDomain + convention + break target) — local/testing only';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: local/testing only.');

            return self::FAILURE;
        }

        $resolver = app(WorkforceRuleResolver::class);

        // ─── Company A — existing Leezr Logistics (owner@leezr.test) ──────────────
        $ownerA = User::where('email', 'owner@leezr.test')->firstOrFail();
        $companyA = Membership::where('user_id', $ownerA->id)->firstOrFail()->company;
        $resolver->setBreakRule($companyA, 'target_duration_minutes', (int) $this->option('a-minutes'));
        $this->info("Company A #{$companyA->id} ({$companyA->name}, {$companyA->jobdomain_key}): break target {$this->option('a-minutes')} min.");

        // ─── Company B — a different trade, its own convention and owner-employee ──
        Jobdomain::firstOrCreate(
            ['key' => 'beta-services'],
            ['label' => 'Beta Services', 'is_active' => true, 'default_modules' => ['workforce', 'workforce_planning', 'workforce_payroll']],
        );

        $companyB = Company::firstOrCreate(
            ['slug' => 'beta-services-e2e'],
            ['name' => 'Beta Services', 'plan_key' => 'pro', 'market_key' => 'FR', 'jobdomain_key' => 'beta-services', 'status' => 'active'],
        );

        // A distinct convention collective for Company B (proves JobDomain ≠ single CC).
        $cc = ConventionCollective::firstOrCreate(
            ['idcc' => '9999'],
            ['short_name' => 'CC Beta', 'full_name' => 'Convention Beta Services', 'market_key' => 'FR', 'is_active' => true],
        );
        ConventionCollectiveRule::firstOrCreate(
            ['convention_collective_id' => $cc->id, 'domain' => 'workforce', 'rule_key' => 'break_min_minutes'],
            ['rule_type' => 'working_time', 'value' => ['amount' => 25], 'effective_from' => '2020-01-01', 'override_mode' => 'minimum', 'source' => 'CC Beta', 'reference' => 'Art. 12'],
        );
        if ($companyB->default_convention_collective_id !== $cc->id) {
            $companyB->update(['default_convention_collective_id' => $cc->id]);
        }

        // Enable the workforce modules for Company B (entitled via its JobDomain default_modules).
        foreach (['workforce', 'workforce_planning', 'workforce_payroll'] as $key) {
            $res = ModuleActivationEngine::enable($companyB, $key);
            if (! ($res['success'] ?? true)) {
                $this->warn("Module {$key} not enabled for Company B: ".($res['data']['message'] ?? 'unknown'));
            }
        }

        // Owner-employee for Company B so they can both administer AND clock.
        $ownerB = User::firstOrCreate(
            ['email' => 'ownerb@leezr.test'],
            ['name' => 'Owner Beta', 'password' => Hash::make('password')],
        );
        Membership::firstOrCreate(
            ['user_id' => $ownerB->id, 'company_id' => $companyB->id],
            ['role' => 'owner'],
        );
        $employeeB = Employee::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyB->id, 'user_id' => $ownerB->id],
            ['first_name' => 'Owner', 'last_name' => 'Beta', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01', 'employee_number' => 'BETA-001'],
        );
        EmploymentContract::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyB->id, 'employee_id' => $employeeB->id, 'is_current' => true],
            ['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => EmploymentContract::STATUS_ACTIVE, 'start_date' => '2024-01-01', 'weekly_hours' => 35, 'convention_collective_id' => $cc->id],
        );

        $resolver->setBreakRule($companyB, 'target_duration_minutes', (int) $this->option('b-minutes'));
        $this->info("Company B #{$companyB->id} ({$companyB->name}, {$companyB->jobdomain_key}, CC {$cc->idcc}): break target {$this->option('b-minutes')} min.");

        $this->info("Isolation fixture ready: A=#{$companyA->id} ({$this->option('a-minutes')}'), B=#{$companyB->id} ({$this->option('b-minutes')}') — owners owner@leezr.test / ownerb@leezr.test.");

        return self::SUCCESS;
    }
}
