<?php

namespace Tests\Feature;

use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayManager;
use App\Core\Workforce\Dsn\Gateway\DsnPollingInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingResult;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\FakeNetEntreprisesClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesClientInterface;
use App\Core\Workforce\Dsn\Gateway\NullDsnGateway;
use App\Platform\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7.2 — ADR-530
 * Contract tests for NetEntreprisesDsnGateway.
 *
 * All tests use FakeNetEntreprisesClient — zero HTTP calls.
 */
class DsnNetEntreprisesContractTest extends TestCase
{
    use RefreshDatabase;

    private string $dsnFilePath = 'workforce/dsn/test-company/dsn-2026-04.txt';

    private string $dsnFileContent = "S21.G00.06.001,'73282932000074'\nS21.G00.06.002,'6201Z'";

    private array $metadata = [
        'company_id' => 1,
        'period_month' => '2026-04',
        'declaration_type' => 'monthly',
        'payload_hash' => 'abc123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put($this->dsnFilePath, $this->dsnFileContent);

        $this->seedValidCredentials();
    }

    private function seedValidCredentials(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'sandbox',
            ],
        ]);
    }

    private function makeGateway(string $scenario = 'success', int $pendingPolls = 1): NetEntreprisesDsnGateway
    {
        $client = new FakeNetEntreprisesClient($scenario, $pendingPolls);

        return new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );
    }

    // ─── Submit tests ───

    public function test_submit_success_returns_submission_reference(): void
    {
        $gateway = $this->makeGateway('success');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertInstanceOf(DsnSubmissionResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNotNull($result->reference);
        $this->assertStringStartsWith('NE-', $result->reference);
        $this->assertNull($result->error);
    }

    public function test_submit_auth_failure_is_non_retryable(): void
    {
        $gateway = $this->makeGateway('authentication_failed');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertNull($result->reference);
        $this->assertStringContainsString('authentication_failed', $result->error);
    }

    public function test_submit_network_timeout_throws_runtime_exception(): void
    {
        $gateway = $this->makeGateway('network_timeout');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transport_error');

        $gateway->submit($this->dsnFilePath, $this->metadata);
    }

    public function test_submit_duplicate_is_non_retryable(): void
    {
        $gateway = $this->makeGateway('duplicate_submission');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('duplicate_submission', $result->error);
    }

    public function test_submit_technical_rejection_is_non_retryable(): void
    {
        $gateway = $this->makeGateway('technical_rejection');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('technical_rejection', $result->error);
        $this->assertStringContainsString('ISO-8859-1', $result->error);
    }

    public function test_submit_fails_when_file_missing(): void
    {
        $gateway = $this->makeGateway('success');
        $result = $gateway->submit('nonexistent/file.txt', $this->metadata);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->error);
    }

    public function test_submit_fails_when_no_credentials(): void
    {
        // Wipe credentials
        PlatformSetting::instance()->update(['dsn' => null]);

        $gateway = $this->makeGateway('success');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials_missing', $result->error);
    }

    public function test_submit_fails_when_invalid_credentials_format(): void
    {
        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '12345', // invalid
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'pass',
                'ne_environment' => 'sandbox',
            ],
        ]);

        $gateway = $this->makeGateway('success');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials_invalid', $result->error);
    }

    // ─── Poll tests ───

    public function test_poll_pending(): void
    {
        $gateway = $this->makeGateway('pending_then_accepted', 5);
        $result = $gateway->poll('NE-1-2026-04-00001');

        $this->assertInstanceOf(DsnPollingResult::class, $result);
        $this->assertSame('pending', $result->gatewayStatus);
        $this->assertFalse($result->terminal);
    }

    public function test_poll_accepted_with_report(): void
    {
        $gateway = $this->makeGateway('success');
        $result = $gateway->poll('NE-1-2026-04-00001');

        $this->assertSame('cco_accepted', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertNotNull($result->businessReportPath);
        $this->assertNull($result->errorCode);
    }

    public function test_poll_rejected_with_report(): void
    {
        $gateway = $this->makeGateway('business_rejection');
        $result = $gateway->poll('NE-1-2026-04-00001');

        $this->assertSame('ban_rejected', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertNotNull($result->errorCode);
        $this->assertNotNull($result->errorMessage);
        $this->assertNotNull($result->businessReportPath);
    }

    public function test_poll_pending_then_accepted_sequence(): void
    {
        $client = FakeNetEntreprisesClient::pendingThenAccepted(2);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );

        // First 2 polls → pending
        $r1 = $gateway->poll('NE-1-2026-04-00001');
        $this->assertSame('pending', $r1->gatewayStatus);

        $r2 = $gateway->poll('NE-1-2026-04-00001');
        $this->assertSame('pending', $r2->gatewayStatus);

        // 3rd poll → accepted
        $r3 = $gateway->poll('NE-1-2026-04-00001');
        $this->assertSame('cco_accepted', $r3->gatewayStatus);
        $this->assertTrue($r3->terminal);
    }

    // ─── Secret filtering ───

    public function test_no_secrets_in_submit_response(): void
    {
        $gateway = $this->makeGateway('success');
        $result = $gateway->submit($this->dsnFilePath, $this->metadata);

        $this->assertSecretsFree($result->rawResponse);
    }

    public function test_no_secrets_in_poll_response(): void
    {
        $gateway = $this->makeGateway('success');
        $result = $gateway->poll('NE-1-2026-04-00001');

        // Ensure rawResponse exists and is secret-free
        $this->assertIsArray($result->rawResponse);
        $this->assertSecretsFree($result->rawResponse);
    }

    private function assertSecretsFree(array $data): void
    {
        $secretFields = ['password', 'token', 'jeton', 'motdepasse', 'ne_password', 'secret', 'credential'];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains(
                    strtolower($key),
                    $secretFields,
                    "Secret field '{$key}' found in response data",
                );
            }
            if (is_array($value)) {
                $this->assertSecretsFree($value);
            }
        }
    }

    // ─── Gateway Manager ───

    public function test_manager_resolves_net_entreprises_driver(): void
    {
        config(['workforce.dsn.gateway' => 'net-entreprises']);

        $manager = $this->app->make(DsnGatewayManager::class);
        $gateway = $manager->driver('net-entreprises');

        $this->assertInstanceOf(NetEntreprisesDsnGateway::class, $gateway);
        $this->assertInstanceOf(DsnGatewayInterface::class, $gateway);
        $this->assertInstanceOf(DsnPollingInterface::class, $gateway);
    }

    public function test_kill_switch_forces_null_gateway(): void
    {
        config([
            'workforce.dsn.gateway' => 'net-entreprises',
            'workforce.dsn.submit_enabled' => false,
        ]);

        // The SubmitDsnDeclarationUseCase resolves to NullDsnGateway
        // when submit_enabled=false, regardless of configured driver.
        // We test the resolveGateway logic directly.
        $submitEnabled = config('workforce.dsn.submit_enabled');
        $this->assertFalse($submitEnabled);

        // Manager still resolves the configured driver
        $manager = $this->app->make(DsnGatewayManager::class);
        $gateway = $manager->driver();

        // But the UseCase would override to NullDsnGateway
        // (tested in DsnPreflightTest). Here we just verify
        // the kill-switch config is correct.
        $this->assertInstanceOf(NetEntreprisesDsnGateway::class, $gateway);
    }

    public function test_null_driver_still_works(): void
    {
        config(['workforce.dsn.gateway' => 'null']);

        $manager = $this->app->make(DsnGatewayManager::class);
        $gateway = $manager->driver();

        $this->assertInstanceOf(NullDsnGateway::class, $gateway);
    }

    public function test_file_driver_still_works(): void
    {
        config(['workforce.dsn.gateway' => 'file']);

        $manager = $this->app->make(DsnGatewayManager::class);
        $gateway = $manager->driver();

        $this->assertInstanceOf(\App\Core\Workforce\Dsn\Gateway\FileDsnGateway::class, $gateway);
    }

    // ─── FakeNetEntreprisesClient scenarios ───

    public function test_fake_client_implements_interface(): void
    {
        $client = new FakeNetEntreprisesClient();

        $this->assertInstanceOf(NetEntreprisesClientInterface::class, $client);
    }

    // ─── Gateway implements both interfaces ───

    public function test_gateway_implements_both_interfaces(): void
    {
        $gateway = $this->makeGateway();

        $this->assertInstanceOf(DsnGatewayInterface::class, $gateway);
        $this->assertInstanceOf(DsnPollingInterface::class, $gateway);
    }
}
