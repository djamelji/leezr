<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\Gateway\DsnPollingResult;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesHttpClient;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Core\Markets\Market;
use App\Modules\Workforce\UseCases\PollDsnDeclarationUseCase;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 8.2 — ADR-535
 * Tests for real HTTP polling lifecycle with Http::fake().
 *
 * Validates the full chain: HttpClient → Gateway → UseCase → DsnDeclaration
 * with real XML responses from Net-Entreprises.
 */
class DsnPollingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private string $fileContent = "S21.G00.06.001,'73282932000074'\nS21.G00.06.002,'6201Z'";

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
            'name' => 'Poll HTTP Co', 'slug' => 'poll-http-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '73282932000074', 'naf_code' => '6201Z',
            'address_street' => '10 rue de la Paix',
            'address_postal_code' => '75002', 'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'op-poll-http@test.com',
            'password' => bcrypt('password'),
        ]);

        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'sandbox',
            ],
        ]);

        config([
            'workforce.dsn.submit_enabled' => true,
            'workforce.dsn.max_attempts' => 1,
            'workforce.dsn.backoff_base_seconds' => 0,
            'workforce.dsn.ne_auth_url' => 'https://test-services.net-entreprises.fr/authentifier/1.0/',
            'workforce.dsn.ne_deposit_url' => 'https://test-dsnrg.net-entreprises.fr/deposer-dsn/1.0/',
            'workforce.dsn.ne_status_url' => 'https://test-dsnrg.net-entreprises.fr/consulter-retour/1.0/',
            'workforce.dsn.ne_service_code' => '97',
            'workforce.dsn.ne_timeout_seconds' => 5,
            'workforce.dsn.ne_retry_attempts' => 1,
        ]);

        Model::reguard();
    }

    // ─── XML response builders ───

    private function xmlAuth(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><reponse><jeton>TEST-JETON-XYZ789</jeton></reponse>';
    }

    private function xmlCco(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<retour>
  <statut>accepte</statut>
  <type>CCO</type>
  <certificat>CERT-2026-04</certificat>
  <organismes>
    <cnav><statut>ok</statut></cnav>
    <urssaf><statut>ok</statut></urssaf>
  </organismes>
</retour>
XML;
    }

    private function xmlBan(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<retour>
  <statut>rejete</statut>
  <type>BAN</type>
  <code-anomalie>SIRET_MISMATCH</code-anomalie>
  <message-anomalie>SIRET does not match registered entity for period 2026-04.</message-anomalie>
</retour>
XML;
    }

    private function xmlPending(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<retour>
  <statut>en_cours</statut>
  <type>EN_COURS</type>
  <message>Traitement en cours</message>
</retour>
XML;
    }

    private function xmlAee(string $idFlux = 'NE-20260501-1234567890123'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<retour>
  <idFlux>{$idFlux}</idFlux>
  <type>AEE</type>
  <statut>OK</statut>
</retour>
XML;
    }

    // ─── Helpers ───

    private function makeSubmittedDeclaration(array $overrides = []): DsnDeclaration
    {
        Model::unguard();

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'status' => 'exported', 'currency' => 'EUR',
            'employee_count' => 1, 'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000, 'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:poll-http:' . uniqid(),
        ]);

        $filePath = 'workforce/dsn/' . $this->company->id . '/poll_http_' . uniqid() . '.dsn';
        Storage::disk('local')->put($filePath, $this->fileContent);

        $declaration = DsnDeclaration::create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => DsnDeclaration::STATUS_SUBMITTED,
            'file_path' => $filePath,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', $this->fileContent),
            'generated_by' => $this->user->id,
            'generated_at' => now()->subHour(),
            'exported_by' => $this->user->id,
            'exported_at' => now()->subMinutes(50),
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subMinutes(30),
            'submission_reference' => 'NE-20260501-1234567890123',
            'gateway_driver' => 'net-entreprises',
            'gateway_environment' => 'sandbox',
            'gateway_status' => 'aee_received',
            'attempt_count' => 1,
            'next_poll_at' => now()->subMinute(),
        ], $overrides));

        Model::reguard();

        return $declaration;
    }

    private function makeGateway(): NetEntreprisesDsnGateway
    {
        return new NetEntreprisesDsnGateway(
            client: new NetEntreprisesHttpClient(),
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );
    }

    private function makeSubmitUseCase(): SubmitDsnDeclarationUseCase
    {
        return new SubmitDsnDeclarationUseCase(
            $this->makeGateway(),
            $this->bypassPreflight(),
        );
    }

    private function makePollUseCase(): PollDsnDeclarationUseCase
    {
        return new PollDsnDeclarationUseCase($this->makeSubmitUseCase());
    }

    private function bypassPreflight(): DsnPreflightChecker
    {
        return new class extends DsnPreflightChecker
        {
            public function check(\App\Core\Workforce\DsnDeclaration $declaration): PreflightResult
            {
                return PreflightResult::ready();
            }
        };
    }

    private function fakeHttpForPoll(string $pollXml): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->xmlAuth(), 200),
            'test-dsnrg.net-entreprises.fr/consulter-retour/*' => Http::response($pollXml, 200),
        ]);
    }

    // ─── Gateway poll: CCO accepted ───

    public function test_gateway_poll_cco_returns_accepted_with_report_path(): void
    {
        $this->fakeHttpForPoll($this->xmlCco());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-20260501-1234567890123');

        $this->assertSame('cco_accepted', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertNotNull($result->businessReportPath);
        $this->assertStringContainsString('cco_', $result->businessReportPath);

        // Verify XML archived on disk
        $this->assertTrue(Storage::disk('local')->exists($result->businessReportPath));
        $content = Storage::disk('local')->get($result->businessReportPath);
        $this->assertStringContainsString('CCO', $content);
        $this->assertStringContainsString('certificat', $content);
    }

    // ─── Gateway poll: BAN rejected ───

    public function test_gateway_poll_ban_returns_rejected_with_report_path(): void
    {
        $this->fakeHttpForPoll($this->xmlBan());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-20260501-1234567890123');

        $this->assertSame('ban_rejected', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertNotNull($result->businessReportPath);
        $this->assertStringContainsString('ban_', $result->businessReportPath);

        // Verify XML archived
        $this->assertTrue(Storage::disk('local')->exists($result->businessReportPath));
        $content = Storage::disk('local')->get($result->businessReportPath);
        $this->assertStringContainsString('SIRET_MISMATCH', $content);
    }

    // ─── Gateway poll: pending ───

    public function test_gateway_poll_pending_returns_no_report(): void
    {
        $this->fakeHttpForPoll($this->xmlPending());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-20260501-1234567890123');

        $this->assertSame('pending', $result->gatewayStatus);
        $this->assertFalse($result->terminal);
        $this->assertNull($result->businessReportPath);
    }

    // ─── Full lifecycle: pending → accepted via UseCase ───

    public function test_usecase_poll_cco_transitions_to_accepted(): void
    {
        $declaration = $this->makeSubmittedDeclaration();
        $this->fakeHttpForPoll($this->xmlCco());

        $uc = $this->makePollUseCase();
        $result = $uc->execute($declaration, 0);

        $this->assertSame('accepted', $result['result']);

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $declaration->status);
        $this->assertNotNull($declaration->business_report_path);
        $this->assertStringContainsString('cco_', $declaration->business_report_path);
    }

    // ─── Full lifecycle: BAN → rejected ───

    public function test_usecase_poll_ban_transitions_to_rejected(): void
    {
        $declaration = $this->makeSubmittedDeclaration();
        $this->fakeHttpForPoll($this->xmlBan());

        $uc = $this->makePollUseCase();
        $result = $uc->execute($declaration, 0);

        $this->assertSame('rejected', $result['result']);

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $declaration->status);
        $this->assertSame('SIRET_MISMATCH', $declaration->gateway_error_code);
        $this->assertNotNull($declaration->business_report_path);
    }

    // ─── Full lifecycle: EN_COURS → pending (replanifie) ───

    public function test_usecase_poll_pending_replanifies(): void
    {
        $declaration = $this->makeSubmittedDeclaration(['attempt_count' => 1]);
        $this->fakeHttpForPoll($this->xmlPending());

        $uc = $this->makePollUseCase();
        $result = $uc->execute($declaration, 0);

        $this->assertSame('pending', $result['result']);

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $declaration->status);
        $this->assertNotNull($declaration->next_poll_at);
        $this->assertTrue($declaration->next_poll_at->isFuture());
    }

    // ─── XML malformed → pending (graceful degradation) ───

    public function test_malformed_xml_returns_pending(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->xmlAuth(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('NOT VALID XML {{{{', 200),
        ]);

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-TEST-REF');

        // Malformed = no recognized status markers → pending
        $this->assertSame('pending', $result->gatewayStatus);
        $this->assertFalse($result->terminal);
    }

    // ─── Auth expired during poll → DomainException → rejected in gateway ───

    public function test_auth_expired_during_poll_returns_rejected(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->xmlAuth(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('Unauthorized', 401),
        ]);

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-TEST-REF');

        // DomainException caught by gateway → rejected with polling_error
        $this->assertSame('ban_rejected', $result->gatewayStatus);
        $this->assertSame('polling_error', $result->errorCode);
    }

    // ─── Transport error during poll → RuntimeException propagated ───

    public function test_transport_error_during_poll_throws(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->xmlAuth(), 200),
            'test-dsnrg.net-entreprises.fr/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(\RuntimeException::class);
        $gateway->poll('NE-TEST-REF');
    }

    // ─── Security: no secrets in poll responses ───

    public function test_no_password_in_gateway_poll_result(): void
    {
        $this->fakeHttpForPoll($this->xmlCco());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-TEST-REF');

        $serialized = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('SecureP@ss123', $serialized);
        $this->assertStringNotContainsString('TEST-JETON', $serialized);
    }

    public function test_no_secret_in_archived_report(): void
    {
        $this->fakeHttpForPoll($this->xmlCco());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-TEST-REF');

        $this->assertNotNull($result->businessReportPath);
        $content = Storage::disk('local')->get($result->businessReportPath);

        // The archived XML should be the raw CCO response, no credentials
        $this->assertStringNotContainsString('SecureP@ss123', $content);
        $this->assertStringNotContainsString('TEST-JETON', $content);
    }

    // ─── Submit: AEE receipt archived ───

    public function test_submit_archives_aee_receipt(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->xmlAuth(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->xmlAee(), 200),
        ]);

        $filePath = 'workforce/dsn/test_submit.dsn';
        Storage::disk('local')->put($filePath, $this->fileContent);

        $gateway = $this->makeGateway();
        $result = $gateway->submit($filePath, ['company_id' => 1]);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('archived_receipt_path', $result->rawResponse);

        $receiptPath = $result->rawResponse['archived_receipt_path'];
        $this->assertTrue(Storage::disk('local')->exists($receiptPath));
        $this->assertStringContainsString('aee_', $receiptPath);
    }

    // ─── Ban error details preserved ───

    public function test_ban_error_details_preserved_in_result(): void
    {
        $this->fakeHttpForPoll($this->xmlBan());

        $gateway = $this->makeGateway();
        $result = $gateway->poll('NE-TEST-REF');

        $this->assertSame('SIRET_MISMATCH', $result->errorCode);
        $this->assertStringContainsString('SIRET does not match', $result->errorMessage);
    }
}
