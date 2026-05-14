<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Dsn\DsnGatewayHealthCheck;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Markets\Market;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 8.3 — ADR-536
 * Tests for sandbox validation: dsn:sandbox-submit command,
 * enriched health check (environment + endpoints), dry-run,
 * and security hardening.
 */
class DsnSandboxValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');

        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR',
            'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000,
            'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '',
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Sandbox Co', 'slug' => 'sandbox-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '73282932000074', 'naf_code' => '6201Z',
            'address_street' => '10 rue Test',
            'address_postal_code' => '75001', 'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'sandbox-op@test.com',
            'password' => bcrypt('password'),
        ]);

        Model::reguard();
    }

    private function seedCredentials(string $environment = 'sandbox'): void
    {
        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => $environment,
            ],
        ]);
    }

    private function makeDeclaration(string $status, array $overrides = []): DsnDeclaration
    {
        Model::unguard();

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'status' => 'exported', 'currency' => 'EUR',
            'employee_count' => 1, 'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000, 'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:sandbox:' . uniqid(),
        ]);

        $filePath = 'workforce/dsn/' . $this->company->id . '/sandbox_' . uniqid() . '.dsn';
        Storage::disk('local')->put($filePath, 'S10.G00.00.001 test DSN content');

        $declaration = DsnDeclaration::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'period_month' => '2026-04',
            'status' => $status,
            'file_path' => $filePath,
            'payload_hash' => md5('test'),
            'generated_at' => now(),
        ], $overrides));

        Model::reguard();

        return $declaration;
    }

    // ── Health Check: Environment ─────────────────────────────

    public function test_health_check_shows_sandbox_environment(): void
    {
        $this->seedCredentials('sandbox');

        $check = app(DsnGatewayHealthCheck::class)->check();

        $this->assertEquals('sandbox', $check['environment']);

        $envCheck = collect($check['checks'])->firstWhere('key', 'environment');
        $this->assertEquals('yellow', $envCheck['status']);
        $this->assertStringContainsString('sandbox', $envCheck['detail']);
    }

    public function test_health_check_shows_production_environment(): void
    {
        $this->seedCredentials('production');

        $check = app(DsnGatewayHealthCheck::class)->check();

        $this->assertEquals('production', $check['environment']);

        $envCheck = collect($check['checks'])->firstWhere('key', 'environment');
        $this->assertEquals('green', $envCheck['status']);
        $this->assertStringContainsString('production', $envCheck['detail']);
    }

    // ── Health Check: Endpoints ───────────────────────────────

    public function test_health_check_endpoints_consistent_sandbox(): void
    {
        $this->seedCredentials('sandbox');
        config([
            'workforce.dsn.ne_auth_url' => 'https://test-services.net-entreprises.fr/authentifier/1.0/',
            'workforce.dsn.ne_deposit_url' => 'https://test-dsnrg.net-entreprises.fr/deposer-dsn/1.0/',
            'workforce.dsn.ne_status_url' => 'https://test-dsnrg.net-entreprises.fr/consulter-retour/1.0/',
        ]);

        $check = app(DsnGatewayHealthCheck::class)->check();

        $endpointCheck = collect($check['checks'])->firstWhere('key', 'endpoints');
        $this->assertEquals('green', $endpointCheck['status']);
        $this->assertStringContainsString('Consistent', $endpointCheck['detail']);
    }

    public function test_health_check_endpoints_mismatch_sandbox_with_prod_urls(): void
    {
        $this->seedCredentials('sandbox');
        config([
            'workforce.dsn.ne_auth_url' => 'https://services.net-entreprises.fr/authentifier/1.0/',
            'workforce.dsn.ne_deposit_url' => 'https://dsnrg.net-entreprises.fr/deposer-dsn/1.0/',
        ]);

        $check = app(DsnGatewayHealthCheck::class)->check();

        $endpointCheck = collect($check['checks'])->firstWhere('key', 'endpoints');
        $this->assertEquals('red', $endpointCheck['status']);
        $this->assertStringContainsString('MISMATCH', $endpointCheck['detail']);
    }

    public function test_health_check_endpoints_mismatch_production_with_test_urls(): void
    {
        $this->seedCredentials('production');
        config([
            'workforce.dsn.ne_auth_url' => 'https://test-services.net-entreprises.fr/authentifier/1.0/',
            'workforce.dsn.ne_deposit_url' => 'https://test-dsnrg.net-entreprises.fr/deposer-dsn/1.0/',
        ]);

        $check = app(DsnGatewayHealthCheck::class)->check();

        $endpointCheck = collect($check['checks'])->firstWhere('key', 'endpoints');
        $this->assertEquals('red', $endpointCheck['status']);
        $this->assertStringContainsString('MISMATCH', $endpointCheck['detail']);
    }

    // ── Sandbox Submit Command ────────────────────────────────

    public function test_sandbox_submit_blocked_in_production(): void
    {
        $this->seedCredentials('production');
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_EXPORTED);

        $this->artisan('dsn:sandbox-submit', ['declaration' => $declaration->id])
            ->assertFailed()
            ->expectsOutputToContain('BLOCKED');
    }

    public function test_sandbox_submit_blocked_for_non_exported(): void
    {
        $this->seedCredentials('sandbox');
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_DRAFT);

        $this->artisan('dsn:sandbox-submit', ['declaration' => $declaration->id])
            ->assertFailed()
            ->expectsOutputToContain('not exported');
    }

    public function test_sandbox_submit_blocked_without_credentials(): void
    {
        // No credentials seeded — ne_environment defaults to sandbox in config
        config(['workforce.dsn.ne_environment' => 'sandbox']);
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_EXPORTED);

        $this->artisan('dsn:sandbox-submit', ['declaration' => $declaration->id])
            ->assertFailed()
            ->expectsOutputToContain('credentials');
    }

    public function test_sandbox_submit_blocked_for_nonexistent_declaration(): void
    {
        $this->seedCredentials('sandbox');

        $this->artisan('dsn:sandbox-submit', ['declaration' => 99999])
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }

    public function test_sandbox_submit_dry_run_shows_info_without_http(): void
    {
        $this->seedCredentials('sandbox');
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_EXPORTED);

        $this->artisan('dsn:sandbox-submit', [
            'declaration' => $declaration->id,
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry-run')
            ->expectsOutputToContain($declaration->payload_hash);
    }

    // ── Security ──────────────────────────────────────────────

    public function test_sandbox_submit_dry_run_does_not_leak_password(): void
    {
        $this->seedCredentials('sandbox');
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_EXPORTED);

        $result = $this->artisan('dsn:sandbox-submit', [
            'declaration' => $declaration->id,
            '--dry-run' => true,
        ]);

        $result->assertSuccessful();

        // The output should not contain the password
        $result->doesntExpectOutputToContain('SecureP@ss123');
    }

    public function test_health_check_does_not_leak_credentials(): void
    {
        $this->seedCredentials('sandbox');

        $check = app(DsnGatewayHealthCheck::class)->check();

        $json = json_encode($check);
        $this->assertStringNotContainsString('SecureP@ss123', $json);
        $this->assertStringNotContainsString('ne_password', $json);
    }

    // ── Health Check: 8 checks total ──────────────────────────

    public function test_health_check_returns_8_checks(): void
    {
        $this->seedCredentials('sandbox');

        $check = app(DsnGatewayHealthCheck::class)->check();

        $this->assertCount(8, $check['checks']);

        $keys = array_column($check['checks'], 'key');
        $this->assertContains('environment', $keys);
        $this->assertContains('endpoints', $keys);
        $this->assertContains('submit_enabled', $keys);
        $this->assertContains('credentials', $keys);
        $this->assertContains('gateway_driver', $keys);
        $this->assertContains('last_submit', $keys);
        $this->assertContains('recent_errors', $keys);
        $this->assertContains('pending_polls', $keys);
    }

    // ── Runbook exists ────────────────────────────────────────

    public function test_runbook_contains_sandbox_validation_section(): void
    {
        $runbook = file_get_contents(base_path('docs/dsn-runbook.md'));

        $this->assertStringContainsString('Validation sandbox', $runbook);
        $this->assertStringContainsString('dsn:sandbox-submit', $runbook);
        $this->assertStringContainsString('Critères de succès sandbox', $runbook);
        $this->assertStringContainsString('Critères de blocage sandbox', $runbook);
        $this->assertStringContainsString('Checklist activation production', $runbook);
    }
}
