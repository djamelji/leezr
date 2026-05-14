<?php

namespace Tests\Feature;

use App\Core\Workforce\Dsn\Gateway\DsnPollingInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingResult;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\DsnDeclaration;
use App\Platform\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Sprint 7.1 — ADR-529
 * Tests for DSN Gateway Foundations:
 * - DsnPollingInterface + DsnPollingResult
 * - DsnCredentialService (read, validate, encrypt/decrypt)
 * - DsnDeclaration new gateway tracking fields
 * - config/workforce.php NE settings
 * - dsn:check-credentials command
 */
class DsnGatewayFoundationsTest extends TestCase
{
    use RefreshDatabase;

    // ─── DsnPollingResult DTO ───

    public function test_polling_result_pending_factory(): void
    {
        $result = DsnPollingResult::pending(['raw' => true]);

        $this->assertSame('pending', $result->gatewayStatus);
        $this->assertFalse($result->terminal);
        $this->assertNull($result->technicalReceiptPath);
        $this->assertNull($result->businessReportPath);
        $this->assertEmpty($result->metadata);
        $this->assertNull($result->errorCode);
        $this->assertSame(['raw' => true], $result->rawResponse);
    }

    public function test_polling_result_accepted_factory(): void
    {
        $result = DsnPollingResult::accepted(
            businessReportPath: 'workforce/dsn/reports/cco-42.xml',
            metadata: ['cnav' => ['status' => 'ok']],
        );

        $this->assertSame('cco_accepted', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertSame('workforce/dsn/reports/cco-42.xml', $result->businessReportPath);
        $this->assertSame(['cnav' => ['status' => 'ok']], $result->metadata);
        $this->assertNull($result->errorCode);
    }

    public function test_polling_result_rejected_factory(): void
    {
        $result = DsnPollingResult::rejected(
            errorCode: 'BIZ_SIRET_INVALID',
            errorMessage: 'SIRET mismatch with registered entity',
            businessReportPath: 'workforce/dsn/reports/ban-42.xml',
        );

        $this->assertSame('ban_rejected', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertSame('BIZ_SIRET_INVALID', $result->errorCode);
        $this->assertSame('SIRET mismatch with registered entity', $result->errorMessage);
        $this->assertSame('workforce/dsn/reports/ban-42.xml', $result->businessReportPath);
    }

    public function test_polling_result_timeout_factory(): void
    {
        $result = DsnPollingResult::timeout();

        $this->assertSame('poll_timeout', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertSame('polling_timeout', $result->errorCode);
    }

    // ─── DsnPollingInterface contract ───

    public function test_polling_interface_can_be_implemented(): void
    {
        $fake = new class implements DsnPollingInterface
        {
            public function poll(string $submissionReference): DsnPollingResult
            {
                return DsnPollingResult::pending();
            }
        };

        $result = $fake->poll('NE-FLUX-123456');
        $this->assertSame('pending', $result->gatewayStatus);
    }

    // ─── DsnCredentialService ───

    public function test_credential_service_returns_null_when_no_dsn_settings(): void
    {
        $service = new DsnCredentialService();

        $this->assertNull($service->getCredentials());
        $this->assertFalse($service->hasCredentials());
    }

    public function test_credential_service_reads_credentials_from_platform_settings(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecretP@ss',
                'ne_environment' => 'sandbox',
            ],
        ]);

        $service = new DsnCredentialService();
        $credentials = $service->getCredentials();

        $this->assertNotNull($credentials);
        $this->assertSame('73282932000074', $credentials['ne_siret']);
        $this->assertSame('DUPONT', $credentials['ne_nom']);
        $this->assertSame('Jean', $credentials['ne_prenom']);
        $this->assertSame('SecretP@ss', $credentials['ne_password']);
        $this->assertSame('sandbox', $credentials['ne_environment']);
        $this->assertTrue($service->hasCredentials());
    }

    public function test_credential_service_password_is_encrypted_in_database(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'MyS3cretP@ss!',
                'ne_environment' => 'sandbox',
            ],
        ]);

        // Read raw from DB — password should be encrypted
        $raw = PlatformSetting::query()
            ->select('dsn')
            ->first()
            ->getRawOriginal('dsn');

        $rawData = json_decode($raw, true);
        $this->assertNotSame('MyS3cretP@ss!', $rawData['ne_password']);
        // But accessible via model accessor
        $this->assertSame('MyS3cretP@ss!', $settings->fresh()->dsn['ne_password']);
    }

    public function test_credential_service_validates_siret_format(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '12345', // too short
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'pass',
                'ne_environment' => 'sandbox',
            ],
        ]);

        $service = new DsnCredentialService();
        $errors = $service->validateFormats();

        $this->assertArrayHasKey('ne_siret', $errors);
        $this->assertStringContainsString('14 digits', $errors['ne_siret']);
    }

    public function test_credential_service_validates_siret_luhn(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000075', // bad Luhn (last digit wrong)
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'pass',
                'ne_environment' => 'sandbox',
            ],
        ]);

        $service = new DsnCredentialService();
        $errors = $service->validateFormats();

        $this->assertArrayHasKey('ne_siret', $errors);
        $this->assertStringContainsString('Luhn', $errors['ne_siret']);
    }

    public function test_credential_service_validates_environment(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'pass',
                'ne_environment' => 'staging', // invalid
            ],
        ]);

        $service = new DsnCredentialService();
        $errors = $service->validateFormats();

        $this->assertArrayHasKey('ne_environment', $errors);
    }

    public function test_credential_service_valid_credentials_no_errors(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'production',
            ],
        ]);

        $service = new DsnCredentialService();
        $errors = $service->validateFormats();

        $this->assertEmpty($errors);
    }

    public function test_credential_service_missing_fields_detected(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                // missing ne_nom, ne_prenom, ne_password
            ],
        ]);

        $service = new DsnCredentialService();

        $this->assertFalse($service->hasCredentials());

        $errors = $service->validateFormats();
        $this->assertArrayHasKey('ne_nom', $errors);
        $this->assertArrayHasKey('ne_prenom', $errors);
        $this->assertArrayHasKey('ne_password', $errors);
    }

    // ─── DsnDeclaration gateway tracking fields ───

    public function test_dsn_declaration_gateway_fields_fillable(): void
    {
        $declaration = new DsnDeclaration();

        $declaration->fill([
            'gateway_driver' => 'net-entreprises',
            'gateway_environment' => 'sandbox',
            'technical_receipt_path' => 'workforce/dsn/receipts/aee-42.xml',
            'business_report_path' => 'workforce/dsn/reports/cco-42.xml',
            'gateway_status' => 'aee_received',
            'gateway_error_code' => null,
            'gateway_error_message' => null,
            'gateway_metadata' => ['cnav' => ['ok' => true]],
            'attempt_count' => 2,
        ]);

        $this->assertSame('net-entreprises', $declaration->gateway_driver);
        $this->assertSame('sandbox', $declaration->gateway_environment);
        $this->assertSame('aee_received', $declaration->gateway_status);
        $this->assertSame(2, $declaration->attempt_count);
        $this->assertIsArray($declaration->gateway_metadata);
    }

    public function test_dsn_declaration_gateway_metadata_cast_to_array(): void
    {
        $declaration = new DsnDeclaration();
        $declaration->gateway_metadata = ['cnav' => ['code' => '20'], 'urssaf' => ['code' => '60']];

        $this->assertIsArray($declaration->gateway_metadata);
        $this->assertSame('20', $declaration->gateway_metadata['cnav']['code']);
    }

    public function test_dsn_declaration_polling_timestamps_cast(): void
    {
        $declaration = new DsnDeclaration();
        $now = now();
        $declaration->last_polled_at = $now;
        $declaration->next_poll_at = $now->addMinutes(5);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $declaration->last_polled_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $declaration->next_poll_at);
    }

    // ─── Config ───

    public function test_workforce_config_has_ne_settings(): void
    {
        $this->assertNotNull(config('workforce.dsn.ne_environment'));
        $this->assertNotNull(config('workforce.dsn.ne_auth_url'));
        $this->assertNotNull(config('workforce.dsn.ne_deposit_url'));
        $this->assertNotNull(config('workforce.dsn.ne_status_url'));
        $this->assertNotNull(config('workforce.dsn.ne_token_ttl_minutes'));
        $this->assertNotNull(config('workforce.dsn.poll_initial_delay_seconds'));
        $this->assertNotNull(config('workforce.dsn.poll_interval_seconds'));
        $this->assertNotNull(config('workforce.dsn.poll_max_duration_hours'));
    }

    public function test_workforce_config_defaults_to_sandbox(): void
    {
        $this->assertSame('sandbox', config('workforce.dsn.ne_environment'));
        $this->assertStringContainsString('test', config('workforce.dsn.ne_auth_url'));
        $this->assertStringContainsString('test', config('workforce.dsn.ne_deposit_url'));
        $this->assertStringContainsString('test', config('workforce.dsn.ne_status_url'));
    }

    public function test_workforce_config_polling_defaults(): void
    {
        $this->assertSame(15, config('workforce.dsn.poll_initial_delay_seconds'));
        $this->assertSame(300, config('workforce.dsn.poll_interval_seconds'));
        $this->assertSame(72, config('workforce.dsn.poll_max_duration_hours'));
    }

    // ─── dsn:check-credentials command ───

    public function test_check_credentials_command_fails_when_no_credentials(): void
    {
        $exitCode = Artisan::call('dsn:check-credentials');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NOT configured', Artisan::output());
    }

    public function test_check_credentials_command_succeeds_with_valid_credentials(): void
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

        $exitCode = Artisan::call('dsn:check-credentials');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('valid', Artisan::output());
    }

    public function test_check_credentials_command_fails_with_bad_siret(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'dsn' => [
                'ne_siret' => '12345',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'pass',
                'ne_environment' => 'sandbox',
            ],
        ]);

        $exitCode = Artisan::call('dsn:check-credentials');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('14 digits', Artisan::output());
    }

    // ─── DsnSubmissionResult unchanged ───

    public function test_submission_result_success_unchanged(): void
    {
        $result = DsnSubmissionResult::success('NE-FLUX-123', ['aee' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('NE-FLUX-123', $result->reference);
        $this->assertNull($result->error);
        $this->assertSame(['aee' => true], $result->rawResponse);
    }

    public function test_submission_result_failure_unchanged(): void
    {
        $result = DsnSubmissionResult::failure('ARE: technical rejection', ['are' => true]);

        $this->assertFalse($result->success);
        $this->assertNull($result->reference);
        $this->assertSame('ARE: technical rejection', $result->error);
    }
}
