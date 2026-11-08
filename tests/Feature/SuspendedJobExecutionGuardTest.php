<?php

namespace Tests\Feature;

use App\Core\Automation\Jobs\ProcessWorkflowJob;
use App\Core\Automation\WorkflowRule;
use App\Core\Automation\WorkflowExecutionLog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Tenancy\SuspensionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RELEASE-READINESS-V1 (ADR-737) — §3 : un job MÉTIER déjà en file ne doit pas s'exécuter pour une
 * Company suspendue (la suspension n'était qu'une barrière HTTP). Billing/dunning/plateforme exemptés.
 */
class SuspendedJobExecutionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->company = Company::create(['name' => 'JobGuard Co', 'slug' => 'jobguard-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'status' => 'active']);
        Model::reguard();
    }

    private function makeRule(): WorkflowRule
    {
        return WorkflowRule::create([
            'company_id' => $this->company->id,
            'name' => 'Test rule',
            'trigger_topic' => 'test.topic',
            'conditions' => [],
            'actions' => [],
            'enabled' => true,
        ]);
    }

    // Garde unitaire.
    public function test_guard_reports_suspension(): void
    {
        $this->assertFalse(SuspensionGuard::companySuspended(null));
        $this->assertFalse(SuspensionGuard::companySuspended($this->company->id));
        $this->company->update(['status' => 'suspended']);
        $this->assertTrue(SuspensionGuard::companySuspended($this->company->id));
    }

    // Company ACTIVE : le job métier s'exécute (une trace d'exécution est écrite).
    public function test_business_job_runs_for_active_company(): void
    {
        $rule = $this->makeRule();
        (new ProcessWorkflowJob($rule->id, 'test.topic', []))->handle();

        $this->assertTrue(
            WorkflowExecutionLog::withoutGlobalScopes()->where('workflow_rule_id', $rule->id)->exists(),
            'un automatisme doit s\'exécuter pour une company active'
        );
    }

    // Job mis en file AVANT suspension, exécuté APRÈS : le garde refuse l'exécution (aucune trace).
    public function test_business_job_skipped_after_suspension(): void
    {
        $rule = $this->makeRule(); // en file alors que la company est active
        $this->company->update(['status' => 'suspended']); // suspension entre dispatch et exécution

        (new ProcessWorkflowJob($rule->id, 'test.topic', []))->handle();

        $this->assertFalse(
            WorkflowExecutionLog::withoutGlobalScopes()->where('workflow_rule_id', $rule->id)->exists(),
            'un automatisme métier ne doit PAS s\'exécuter pour une company suspendue'
        );
    }
}
