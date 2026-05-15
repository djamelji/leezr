<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Dsn\DsnGatewayHealthCheck;
use App\Core\Workforce\Dsn\DsnMetricsService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\FakeNetEntreprisesClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Markets\Market;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7.5 — ADR-533
 * Tests for DsnGatewayHealthCheck, DsnMetricsService,
 * dsn:retry-rejected, security hardening, and runbook.
 */
class DsnProductionHardeningTest extends TestCase
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
            'name' => 'Hardening Co', 'slug' => 'hardening-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '73282932000074', 'naf_code' => '6201Z',
            'address_street' => '10 rue de la Paix',
            'address_postal_code' => '75002', 'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'op-harden@test.com',
            'password' => bcrypt('password'),
        ]);

        Model::reguard();
    }

    private function seedCredentials(): void
    {
        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'sandbox',
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
            'idempotency_key' => 'payroll:harden:' . uniqid(),
        ]);

        $filePath = 'workforce/dsn/' . $this->company->id . '/harden_' . uniqid() . '.dsn';
        Storage::disk('local')->put($filePath, 'S21.test');

        $declaration = DsnDeclaration::create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => $status,
            'file_path' => $filePath,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', 'S21.test'),
            'generated_by' => $this->user->id,
            'generated_at' => now()->subHour(),
            'exported_by' => $this->user->id,
            'exported_at' => now()->subMinutes(50),
        ], $overrides));

        Model::reguard();

        return $declaration;
    }

    // ─── Health Check: green ───

    public function test_health_green_when_all_configured(): void
    {
        // Use production environment + prod URLs to get all-green
        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'production',
            ],
        ]);
        config(['workforce.dsn.submit_enabled' => true]);
        config(['workforce.dsn.gateway' => 'net-entreprises']);
        config(['workforce.dsn.ne_auth_url' => 'https://services.net-entreprises.fr/authentifier/1.0/']);
        config(['workforce.dsn.ne_deposit_url' => 'https://dsnrg.net-entreprises.fr/deposer-dsn/1.0/']);
        config(['workforce.dsn.ne_status_url' => 'https://dsnrg.net-entreprises.fr/consulter-retour/1.0/']);

        // Create an accepted declaration to avoid "no submissions" yellow
        $this->makeDeclaration(DsnDeclaration::STATUS_ACCEPTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subDay(),
            'gateway_driver' => 'net-entreprises',
            'gateway_status' => 'cco_accepted',
        ]);

        $check = new DsnGatewayHealthCheck(new DsnCredentialService());
        $result = $check->check();

        $this->assertSame('green', $result['status']);
        $this->assertNotEmpty($result['checks']);
    }

    // ─── Health Check: yellow ───

    public function test_health_yellow_when_submit_disabled(): void
    {
        $this->seedCredentials();
        config(['workforce.dsn.submit_enabled' => false]);

        $check = new DsnGatewayHealthCheck(new DsnCredentialService());
        $result = $check->check();

        $this->assertSame('yellow', $result['status']);

        $submitCheck = collect($result['checks'])->firstWhere('key', 'submit_enabled');
        $this->assertSame('yellow', $submitCheck['status']);
    }

    // ─── Health Check: red ───

    public function test_health_red_when_no_credentials(): void
    {
        // Don't seed credentials
        config(['workforce.dsn.submit_enabled' => true]);

        $check = new DsnGatewayHealthCheck(new DsnCredentialService());
        $result = $check->check();

        $this->assertSame('red', $result['status']);

        $credCheck = collect($result['checks'])->firstWhere('key', 'credentials');
        $this->assertSame('red', $credCheck['status']);
    }

    // ─── Health Check: submit_enabled visible ───

    public function test_health_shows_submit_enabled_state(): void
    {
        $this->seedCredentials();
        config(['workforce.dsn.submit_enabled' => false]);

        $check = new DsnGatewayHealthCheck(new DsnCredentialService());
        $result = $check->check();

        $submitCheck = collect($result['checks'])->firstWhere('key', 'submit_enabled');
        $this->assertStringContainsString('Disabled', $submitCheck['detail']);
    }

    // ─── Metrics: counts ───

    public function test_metrics_count_submitted_accepted_rejected(): void
    {
        $this->makeDeclaration(DsnDeclaration::STATUS_SUBMITTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subHour(),
            'gateway_driver' => 'net-entreprises',
        ]);
        $this->makeDeclaration(DsnDeclaration::STATUS_ACCEPTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subDay(),
            'gateway_status' => 'cco_accepted',
        ]);
        $this->makeDeclaration(DsnDeclaration::STATUS_REJECTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subDay(),
            'gateway_status' => 'ban_rejected',
            'gateway_error_code' => 'BAN001',
        ]);

        $metrics = new DsnMetricsService();
        $data = $metrics->collect();

        $this->assertSame(1, $data['dsn_declarations_submitted_total']);
        $this->assertSame(1, $data['dsn_declarations_accepted_total']);
        $this->assertSame(1, $data['dsn_declarations_rejected_total']);
        $this->assertSame(1, $data['dsn_gateway_errors_total']);
        $this->assertArrayHasKey('collected_at', $data);
    }

    // ─── Metrics: acceptance delay ───

    public function test_metrics_average_acceptance_delay(): void
    {
        $this->makeDeclaration(DsnDeclaration::STATUS_ACCEPTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subMinutes(60),
            'gateway_status' => 'cco_accepted',
        ]);

        $metrics = new DsnMetricsService();
        $delay = $metrics->averageAcceptanceDelayMinutes();

        $this->assertNotNull($delay);
        $this->assertGreaterThan(0, $delay);
    }

    // ─── Retry rejected ───

    public function test_retry_rejected_resets_to_exported(): void
    {
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_REJECTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subDay(),
            'submission_reference' => 'NE-REJECTED-001',
            'gateway_status' => 'ban_rejected',
            'gateway_error_code' => 'BAN_FORMAT',
            'gateway_error_message' => 'Invalid block S21',
            'attempt_count' => 3,
        ]);

        $this->artisan('dsn:retry-rejected', [
            'declaration' => $declaration->id,
            '--force' => true,
        ])->assertSuccessful();

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $declaration->status);
        $this->assertNull($declaration->gateway_status);
        $this->assertNull($declaration->gateway_error_code);
        $this->assertNull($declaration->gateway_error_message);
        $this->assertNull($declaration->submission_reference);
        $this->assertSame(0, $declaration->attempt_count);
    }

    public function test_retry_rejected_fails_on_non_rejected(): void
    {
        $declaration = $this->makeDeclaration(DsnDeclaration::STATUS_SUBMITTED, [
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ]);

        $this->artisan('dsn:retry-rejected', [
            'declaration' => $declaration->id,
            '--force' => true,
        ])->assertFailed();

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $declaration->status);
    }

    // ─── Security: no secret leakage in gateway response ───

    public function test_no_secret_in_gateway_submit_response(): void
    {
        $this->seedCredentials();

        $client = new FakeNetEntreprisesClient('success');
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );

        $filePath = 'workforce/dsn/test_secret.dsn';
        Storage::disk('local')->put($filePath, 'S21.test');

        $result = $gateway->submit($filePath, ['company_id' => 1]);

        $this->assertSecretFree($result->rawResponse);
    }

    public function test_no_secret_in_gateway_poll_response(): void
    {
        $this->seedCredentials();

        $client = FakeNetEntreprisesClient::pendingThenAccepted(0);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );

        $result = $gateway->poll('NE-TEST-REF');

        $this->assertSecretFree($result->rawResponse);
    }

    public function test_no_secret_in_cli_credentials_output(): void
    {
        $this->seedCredentials();

        $this->artisan('dsn:check-credentials')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('SecureP@ss123');
    }

    public function test_no_secret_in_health_command_output(): void
    {
        $this->seedCredentials();
        config(['workforce.dsn.submit_enabled' => true]);

        $this->artisan('dsn:gateway-health', ['--metrics' => true])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('SecureP@ss123');
    }

    // ─── Gateway driver fallback ───

    public function test_gateway_driver_fallback_when_disabled(): void
    {
        config(['workforce.dsn.submit_enabled' => false]);
        config(['workforce.dsn.gateway' => 'net-entreprises']);

        // The kill-switch in SubmitDsnDeclarationUseCase forces NullGateway
        // Health check should show yellow for submit_enabled
        $this->seedCredentials();
        $check = new DsnGatewayHealthCheck(new DsnCredentialService());
        $result = $check->check();

        $submitCheck = collect($result['checks'])->firstWhere('key', 'submit_enabled');
        $this->assertSame('yellow', $submitCheck['status']);
        $this->assertStringContainsString('NullGateway', $submitCheck['detail']);
    }

    // ─── Runbook exists ───

    public function test_runbook_file_exists(): void
    {
        $this->assertFileExists(base_path('docs/dsn-runbook.md'));

        $content = file_get_contents(base_path('docs/dsn-runbook.md'));
        $this->assertStringContainsString('submit_enabled', $content);
        $this->assertStringContainsString('Rollback', $content);
        $this->assertStringContainsString('Credentials', $content);
        $this->assertStringContainsString('dsn:gateway-health', $content);
        $this->assertStringContainsString('dsn:retry-rejected', $content);
    }

    // ─── Health command ───

    public function test_gateway_health_command_runs(): void
    {
        $this->seedCredentials();
        config(['workforce.dsn.submit_enabled' => true]);

        $this->artisan('dsn:gateway-health')
            ->assertSuccessful();
    }

    /**
     * Assert that no secret field keys exist in the data structure.
     */
    private function assertSecretFree(array $data): void
    {
        $secretFields = ['password', 'token', 'jeton', 'motdepasse', 'ne_password', 'secret', 'credential'];

        array_walk_recursive($data, function ($value, $key) use ($secretFields) {
            if (is_string($key)) {
                $this->assertNotContains(
                    strtolower($key),
                    $secretFields,
                    "Secret field '{$key}' found in response data"
                );
            }
        });

        $this->assertIsArray($data);
    }
}
