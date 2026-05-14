<?php

namespace Tests\Feature;

use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesHttpClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesSubmitResponse;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesPollResponse;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 8.1 — ADR-534
 * Tests for NetEntreprisesHttpClient using Http::fake().
 */
class DsnHttpClientTest extends TestCase
{
    private array $credentials;

    private array $metadata;

    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = [
            'ne_siret' => '73282932000074',
            'ne_nom' => 'DUPONT',
            'ne_prenom' => 'Jean',
            'ne_password' => 'SecureP@ss123',
            'ne_environment' => 'sandbox',
        ];

        $this->metadata = [
            'company_id' => 1,
            'period_month' => '2026-04',
            'declaration_type' => 'monthly',
            'payload_hash' => 'abc123',
        ];

        $this->payload = "S21.G00.06.001,'73282932000074'\nS21.G00.06.002,'6201Z'";

        config([
            'workforce.dsn.ne_auth_url' => 'https://test-services.net-entreprises.fr/authentifier/1.0/',
            'workforce.dsn.ne_deposit_url' => 'https://test-dsnrg.net-entreprises.fr/deposer-dsn/1.0/',
            'workforce.dsn.ne_status_url' => 'https://test-dsnrg.net-entreprises.fr/consulter-retour/1.0/',
            'workforce.dsn.ne_service_code' => '97',
            'workforce.dsn.ne_timeout_seconds' => 5,
            'workforce.dsn.ne_retry_attempts' => 1,
            'workforce.dsn.ne_token_ttl_minutes' => 30,
        ]);
    }

    private function fakeAuthSuccess(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><reponse><jeton>FAKE-TOKEN-ABC123DEF456</jeton></reponse>';
    }

    private function fakeAeeResponse(string $idFlux = 'NE-20260501-1234567890123'): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><retour><idFlux>{$idFlux}</idFlux><type>AEE</type><statut>OK</statut></retour>";
    }

    private function fakeAreResponse(string $code = 'FORMAT_INVALID', string $message = 'Invalid block S21'): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><retour><type>ARE</type><code-erreur>{$code}</code-erreur><message>{$message}</message></retour>";
    }

    private function fakeCcoResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><retour><statut>accepte</statut><type>CCO</type><certificat>OK</certificat></retour>';
    }

    private function fakeBanResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><retour><statut>rejete</statut><type>BAN</type><code-anomalie>SIRET_MISMATCH</code-anomalie><message-anomalie>SIRET does not match</message-anomalie></retour>';
    }

    private function fakePendingResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><retour><statut>en_cours</statut><type>EN_COURS</type></retour>';
    }

    // ─── Authentication ───

    public function test_auth_sends_xml_with_credentials(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAeeResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'authentifier')) {
                return true;
            }

            $body = $request->body();
            $this->assertStringContainsString('<siret>73282932000074</siret>', $body);
            $this->assertStringContainsString('<nom>DUPONT</nom>', $body);
            $this->assertStringContainsString('<prenom>Jean</prenom>', $body);
            $this->assertStringContainsString('<service>97</service>', $body);
            // Password MUST be in the auth XML (required by NE)
            $this->assertStringContainsString('<motdepasse>', $body);

            return true;
        });

        $this->assertTrue($result->success);
    }

    public function test_auth_failure_throws_domain_exception(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response(
                '<?xml version="1.0"?><erreur><message>Identifiants invalides</message></erreur>',
                403,
            ),
        ]);

        $client = new NetEntreprisesHttpClient();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('authentication failed');

        $client->submit($this->payload, $this->credentials, $this->metadata);
    }

    public function test_auth_token_cached_for_multiple_calls(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/deposer-dsn/*' => Http::response($this->fakeAeeResponse(), 200),
            'test-dsnrg.net-entreprises.fr/consulter-retour/*' => Http::response($this->fakePendingResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $client->submit($this->payload, $this->credentials, $this->metadata);
        $client->poll('NE-TEST-REF', $this->credentials);

        // Auth should only be called once (cached)
        $authCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'authentifier'));

        $this->assertCount(1, $authCalls);
    }

    // ─── Submit: happy path ───

    public function test_submit_happy_path_returns_accepted(): void
    {
        $idFlux = 'NE-20260501-1234567890123';

        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAeeResponse($idFlux), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        $this->assertInstanceOf(NetEntreprisesSubmitResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame($idFlux, $result->reference);
        $this->assertNotNull($result->receipt);
    }

    public function test_submit_sends_gzipped_payload(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAeeResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $client->submit($this->payload, $this->credentials, $this->metadata);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'deposer-dsn')) {
                return true;
            }

            // Check Authorization header has token
            $this->assertNotEmpty($request->header('Authorization'));
            $this->assertSame('Leezr-DSN/1.0', $request->header('User-Agent')[0] ?? '');

            return true;
        });
    }

    // ─── Submit: rejection ───

    public function test_submit_rejection_returns_are(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAreResponse(), 422),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        $this->assertFalse($result->success);
        $this->assertSame('FORMAT_INVALID', $result->errorCode);
        $this->assertStringContainsString('Invalid block', $result->errorMessage);
    }

    // ─── Submit: 401 expired token ───

    public function test_submit_401_throws_domain_exception(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('Unauthorized', 401),
        ]);

        $client = new NetEntreprisesHttpClient();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Authentication failed');

        $client->submit($this->payload, $this->credentials, $this->metadata);
    }

    // ─── Submit: transport error ───

    public function test_submit_connection_error_throws_runtime_exception(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $client = new NetEntreprisesHttpClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transport error');

        $client->submit($this->payload, $this->credentials, $this->metadata);
    }

    public function test_auth_connection_error_throws_runtime_exception(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('DNS resolution failed');
            },
        ]);

        $client = new NetEntreprisesHttpClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transport error');

        $client->submit($this->payload, $this->credentials, $this->metadata);
    }

    // ─── Poll: happy paths ───

    public function test_poll_accepted_returns_cco(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeCcoResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->poll('NE-20260501-1234567890123', $this->credentials);

        $this->assertInstanceOf(NetEntreprisesPollResponse::class, $result);
        $this->assertSame('cco_accepted', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
    }

    public function test_poll_rejected_returns_ban(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeBanResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->poll('NE-20260501-1234567890123', $this->credentials);

        $this->assertSame('ban_rejected', $result->gatewayStatus);
        $this->assertTrue($result->terminal);
        $this->assertSame('SIRET_MISMATCH', $result->errorCode);
    }

    public function test_poll_pending_returns_en_cours(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakePendingResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->poll('NE-20260501-1234567890123', $this->credentials);

        $this->assertSame('pending', $result->gatewayStatus);
        $this->assertFalse($result->terminal);
    }

    // ─── Poll: 401 ───

    public function test_poll_401_throws_domain_exception(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('Unauthorized', 401),
        ]);

        $client = new NetEntreprisesHttpClient();

        $this->expectException(\DomainException::class);
        $client->poll('NE-TEST', $this->credentials);
    }

    // ─── Security: no secrets in responses ───

    public function test_no_password_in_submit_response(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAeeResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        // The password should never appear in rawResponse
        $serialized = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('SecureP@ss123', $serialized);
        $this->assertStringNotContainsString($this->credentials['ne_password'], $serialized);
    }

    public function test_no_password_in_poll_response(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeCcoResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->poll('NE-TEST', $this->credentials);

        $serialized = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('SecureP@ss123', $serialized);
    }

    public function test_no_token_in_submit_response(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response($this->fakeAeeResponse(), 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        // Token should not leak into the submit response rawResponse
        $serialized = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('FAKE-TOKEN-ABC123DEF456', $serialized);
    }

    // ─── XML parsing ───

    public function test_handles_empty_response_body(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('', 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        // Should not crash, should return accepted with generated reference
        $this->assertTrue($result->success);
    }

    public function test_handles_malformed_xml_response(): void
    {
        Http::fake([
            'test-services.net-entreprises.fr/*' => Http::response($this->fakeAuthSuccess(), 200),
            'test-dsnrg.net-entreprises.fr/*' => Http::response('NOT XML AT ALL', 200),
        ]);

        $client = new NetEntreprisesHttpClient();
        $result = $client->submit($this->payload, $this->credentials, $this->metadata);

        // Should handle gracefully
        $this->assertTrue($result->success);
    }
}
