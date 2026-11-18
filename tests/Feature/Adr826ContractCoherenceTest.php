<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\UseCases\CreateContractUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-826 — contract / compensation coherence and controlled remediation of unknown work models. */
class Adr826ContractCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private function contract(Company $c, string $model): EmploymentContract
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'A', 'last_name' => $model, 'hire_date' => '2024-01-01', 'status' => 'active']);

        return EmploymentContract::withoutGlobalScopes()->create(['company_id' => $c->id, 'employee_id' => $e->id, 'contract_type' => 'cdi',
            'work_model_key' => $model, 'weekly_hours' => 35, 'status' => 'active', 'start_date' => '2025-01-01', 'is_current' => true]);
    }

    private function run_(array $args): array
    {
        Artisan::call('workforce:work-model-remediate', $args);

        return json_decode(Artisan::output(), true) ?? [];
    }

    public function test_incoherent_contracts_are_refused_at_creation(): void
    {
        $c = Company::create(['name' => 'Coh', 'slug' => 'coh-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'B', 'last_name' => 'B', 'hire_date' => '2024-01-01', 'status' => 'active']);
        foreach ([['forfait_jours', 'hourly'], ['on_site', 'monthly']] as [$model, $pay]) {
            try {
                app(CreateContractUseCase::class)->execute(employee: $e, contractType: 'cdi', workModelKey: $model, startDate: '2026-01-01', compensationType: $pay);
                $this->fail("{$model} + {$pay} accepted");
            } catch (\DomainException) {
                $this->assertSame(0, EmploymentContract::withoutGlobalScopes()->where('employee_id', $e->id)->count(), 'nothing written');
            }
        }
        $ok = app(CreateContractUseCase::class)->execute(employee: $e, contractType: 'cdi', workModelKey: 'forfait_jours', startDate: '2026-01-01', compensationType: 'monthly');
        $this->assertSame('forfait_jours', $ok->work_model_key);
    }

    public function test_unknown_work_model_remediation_is_explicit_audited_and_reversible(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $bad = $this->contract($a, 'on_site');
        $other = $this->contract($b, 'remote');
        $fine = $this->contract($a, 'horaire');
        $db = DB::connection()->getDatabaseName();

        $diag = $this->run_([]);
        $this->assertSame(['diagnostic_only' => 2], $diag['summary']);
        $this->assertStringContainsString('work mode', $diag['contracts'][0]['hint']);

        // never guessed : --set needs explicit contracts and an allowed model ; --apply needs the database
        $this->assertSame(1, Artisan::call('workforce:work-model-remediate', ['--set' => 'horaire']));
        $this->assertSame(1, Artisan::call('workforce:work-model-remediate', ['--set' => 'on_site', '--contract' => [$bad->id]]));
        $this->assertSame(1, Artisan::call('workforce:work-model-remediate', ['--set' => 'horaire', '--contract' => [$bad->id], '--apply' => true]));
        $this->assertSame('on_site', $bad->fresh()->work_model_key);

        $sim = $this->run_(['--set' => 'horaire', '--contract' => [$bad->id]]);
        $this->assertSame(['would_correct' => 1, 'diagnostic_only' => 1], $sim['summary']);
        $done = $this->run_(['--set' => 'horaire', '--contract' => [$bad->id], '--apply' => true, '--confirm-database' => $db]);
        $this->assertSame('horaire', $bad->fresh()->work_model_key);
        $this->assertSame('remote', $other->fresh()->work_model_key, 'other company untouched');
        $this->assertSame('horaire', $fine->fresh()->work_model_key);
        $log = DB::table('company_audit_logs')->where('action', 'contract.work_model_remediated')->first();
        $this->assertSame((string) $bad->id, $log->target_id);

        $this->assertSame(['would_restore' => 1], $this->run_(['--restore' => $done['batch']])['summary']);
        $this->assertSame(['restored' => 1], $this->run_(['--restore' => $done['batch'], '--apply' => true, '--confirm-database' => $db])['summary']);
        $this->assertSame('on_site', $bad->fresh()->work_model_key);
        $this->assertSame(['already_restored' => 1], $this->run_(['--restore' => $done['batch'], '--apply' => true, '--confirm-database' => $db])['summary']);
    }
}
