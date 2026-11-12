<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\Adapters\ExternalSignatureAdapter;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DMS M6 (ADR-765) — prestataire de signature EXTERNE branché sur le contrat d'adapter horizontal
 * EXISTANT (aucun second moteur). Couvre : contrat d'enveloppe sandbox, sécurité webhook (HMAC-SHA256
 * fail-closed, anti-rejeu horodaté, idempotence, en-têtes absents), honnêteté des niveaux (simple tant
 * que non certifié), mapping des événements, isolation tenant (une référence ne finalise jamais une
 * demande d'une autre Company), document retourné invalide (non-PDF → rejeté), preuves d'intégrité.
 *
 * PÉRIMÈTRE DE VÉRIFICATION : tout est exercé en SANDBOX / fixtures (HTTP simulé). AUCUN appel à un
 * environnement prestataire réel n'est effectué — la validité juridique eIDAS et le comportement réel
 * de l'API restent NON VÉRIFIÉS tant que des credentials réels ne sont pas branchés (cf. ADR-765 §Limites).
 */
class DmsM6ExternalSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $actorId;
    private int $employeeId = 4242;
    private string $secret = 'whsec_m6_test_only';

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');
        Notification::fake();

        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_documents', 'workforce_signature']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Ext Sig Co', 'slug' => 'ext-sig-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->actorId = User::factory()->create()->id;

        // Secret webhook configuré → l'adaptateur externe sort du mode dev-only et vérifie réellement les HMAC.
        config(['signature.external.webhook_secret' => $this->secret]);
        config(['signature.external.replay_tolerance_seconds' => 300]);
    }

    // ── Helpers ──

    private function adapter(): ExternalSignatureAdapter
    {
        return app(ExternalSignatureAdapter::class);
    }

    private function emittedDoc(?Company $company = null): GeneratedDocument
    {
        $company ??= $this->company;
        $tpl = DocumentTemplate::create([
            'company_id' => $company->id, 'code' => 'tpl-'.uniqid(), 'name' => 'Attestation',
            'subject_type' => 'employee', 'body_html' => '<p>{{ company.name }}</p>',
            'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $path = 'documents/generated/'.$company->id.'/2026/11/'.uniqid().'.pdf';
        $realPdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Doc</h1><p>'.uniqid().'</p>')->output();
        Storage::disk('local')->put($path, $realPdf);

        return GeneratedDocument::create([
            'company_id' => $company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'employee', 'subject_id' => $this->employeeId, 'generated_by' => $this->actorId,
            'generated_at' => now(), 'file_path' => $path, 'file_size_bytes' => 10,
            'generation_snapshot' => ['x' => 1], 'signature_status' => GeneratedDocument::SIGNATURE_NONE,
        ]);
    }

    /** Crée + envoie une demande via le prestataire EXTERNE (mode sandbox : aucun credential). */
    private function createAndSendExternal(?Company $company = null): SignatureRequest
    {
        $company ??= $this->company;
        $doc = $this->emittedDoc($company);
        $req = app(CreateSignatureRequestUseCase::class)->execute(
            $company->id, $this->actorId, $doc->id,
            [['role' => SignatureSigner::ROLE_EMPLOYEE, 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]],
            SignatureRequest::LEVEL_SIMPLE, SignatureRequest::ORDER_SEQUENTIAL, ExternalSignatureAdapter::KEY,
        );

        return app(SendSignatureRequestUseCase::class)->execute($company->id, $this->actorId, $req->id);
    }

    /** POST webhook signé HMAC avec horodatage valide. */
    private function postSignedWebhook(array $payload, ?string $tamperBody = null, ?int $ts = null)
    {
        $raw = json_encode($payload);
        $ts ??= now()->getTimestamp();
        $signedBody = $tamperBody ?? $raw;
        $sig = hash_hmac('sha256', $ts.'.'.$signedBody, $this->secret);

        return $this->call('POST', '/api/webhooks/signatures/external', [], [], [], [
            'HTTP_X-Signature-Timestamp' => (string) $ts,
            'HTTP_X-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    // ── 1. Honnêteté : dev-only sans secret, actif avec secret ──
    public function test_dev_only_without_secret_and_active_with_secret(): void
    {
        config(['signature.external.webhook_secret' => '']);
        $this->assertTrue($this->adapter()->isDevOnly());

        config(['signature.external.webhook_secret' => $this->secret]);
        $this->assertFalse($this->adapter()->isDevOnly());
    }

    // ── 2. Niveaux : simple tant que non certifié, même si « advanced » configuré ──
    public function test_levels_capped_to_simple_until_certified(): void
    {
        config(['signature.external.levels' => ['simple', 'advanced', 'qualified'], 'signature.external.certified' => false]);
        $this->assertSame([SignatureRequest::LEVEL_SIMPLE], $this->adapter()->supportedLevels('FR'));
        $this->assertFalse($this->adapter()->supportsLevel('FR', SignatureRequest::LEVEL_QUALIFIED));

        config(['signature.external.certified' => true]);
        $this->assertContains(SignatureRequest::LEVEL_ADVANCED, $this->adapter()->supportedLevels('FR'));
        $this->assertTrue($this->adapter()->isCertified());
    }

    // ── 3. Enveloppe sandbox : références déterministes, marquées non certifiées ──
    public function test_create_envelope_sandbox_returns_deterministic_refs(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        $this->assertSame(ExternalSignatureAdapter::KEY, $req->provider);
        $this->assertStringStartsWith('ext-sandbox-'.$req->id.'-', $req->provider_reference);
        $this->assertSame('ext-signer-'.$req->id.'-'.$signer->id, $signer->evidence_reference);
        $this->assertFalse($this->adapter()->isCertified());
    }

    // ── 4. Webhook valide : le signataire signe, MAIS la demande NE peut PAS « compléter » en sandbox ──
    // Invariant central (anti « simulation présentée comme prod ») : sans preuves d'un prestataire réel,
    // la finalisation échoue à la vérification des preuves → `failed`, et le document reste NON signé.
    // Un vrai prestataire (fetchFinalDocument + verifyEvidence sur document scellé renvoyé) lèvera ce verrou.
    public function test_valid_webhook_signs_but_sandbox_cannot_falsely_complete(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();
        $docId = $req->generated_document_id;

        $this->postSignedWebhook(['id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference])
            ->assertOk()->assertJsonPath('handled', true);

        // Le signataire a bien signé (action tracée) …
        $this->assertSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
        // … mais la demande NE complète PAS sans preuve prestataire réelle (pas de faux succès).
        $this->assertSame(SignatureRequest::STATUS_FAILED, $req->fresh()->status);

        // Le document source N'est PAS marqué « signed » : son intégrité est préservée (reste « pending »).
        $doc = GeneratedDocument::withoutGlobalScopes()->find($docId);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $doc->signature_status);

        // La raison d'échec est bien la non-vérification des preuves prestataire.
        $this->assertDatabaseHas('workforce_signature_events', [
            'signature_request_id' => $req->id,
            'type' => \App\Modules\Workforce\Signature\Models\SignatureEvent::TYPE_VERIFICATION_FAILED,
        ]);
    }

    // ── 5. Idempotence : rejouer le même event_id ne double rien ──
    public function test_repeated_webhook_is_idempotent(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();
        $payload = ['id' => 'dup-1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference];

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
    }

    // ── 6. Webhook forgé (HMAC invalide) → 400, aucun effet ──
    public function test_forged_webhook_rejected(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        // Corps signé différent du corps réellement envoyé → HMAC ne correspond pas.
        $this->postSignedWebhook(
            ['id' => 'f1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference],
            tamperBody: '{"tampered":true}',
        )->assertStatus(400);

        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
    }

    // ── 7. Anti-rejeu : horodatage hors tolérance → 400 ──
    public function test_stale_timestamp_rejected(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        $this->postSignedWebhook(
            ['id' => 'old-1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference],
            ts: now()->getTimestamp() - 3600, // 1h → au-delà de la tolérance de 300s
        )->assertStatus(400);

        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
    }

    // ── 8. En-têtes de signature absents → 400 ──
    public function test_missing_signature_headers_rejected(): void
    {
        $this->postJson('/api/webhooks/signatures/external', ['id' => 'e1', 'type' => 'signer.signed'])
            ->assertStatus(400);
    }

    // ── 9. Prestataire inconnu → 404 ──
    public function test_unknown_provider_404(): void
    {
        $this->call('POST', '/api/webhooks/signatures/nope', [], [], [], [
            'HTTP_X-Signature-Timestamp' => (string) now()->getTimestamp(),
            'HTTP_X-Signature' => 'x', 'CONTENT_TYPE' => 'application/json',
        ], '{"id":"e1","type":"signer.signed"}')->assertStatus(404);
    }

    // ── 10. Refus : event declined → signataire refusé ──
    public function test_declined_webhook_marks_refusal(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        $this->postSignedWebhook([
            'id' => 'd1', 'type' => 'signer.declined',
            'signer_reference' => $signer->evidence_reference, 'reason' => 'Désaccord clause 4',
        ])->assertOk();

        $this->assertSame(SignatureSigner::STATUS_DECLINED, $signer->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_DECLINED, $req->fresh()->status);
    }

    // ── 11. Isolation tenant : une référence d'une autre Company ne finalise jamais notre demande ──
    public function test_cross_tenant_reference_does_not_finalize(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        // Référence signataire inexistante (comme si elle appartenait à une autre Company / demande).
        $this->postSignedWebhook([
            'id' => 'x1', 'type' => 'signer.signed', 'signer_reference' => 'ext-signer-999999-1',
        ])->assertOk(); // toujours 200, mais aucun effet

        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $req->fresh()->status);
    }

    // ── 12. Type d'événement inconnu → ignoré, aucun effet ──
    public function test_unknown_event_type_ignored(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        $this->postSignedWebhook(['id' => 'u1', 'type' => 'envelope.viewed', 'signer_reference' => $signer->evidence_reference])
            ->assertOk();

        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
    }

    // ── 13. Document final retourné non-PDF → rejeté (null) ──
    public function test_fetch_final_document_rejects_non_pdf(): void
    {
        config(['signature.external.api_key' => 'sk_test']);
        Http::fake(['*' => Http::response('<html>error page</html>', 200)]);

        $req = $this->createAndSendExternal();
        $req->metadata = array_merge($req->metadata ?? [], ['final_document_url' => 'https://provider.test/doc/1']);

        $this->assertNull($this->adapter()->fetchFinalDocument($req));
    }

    // ── 14. Document final PDF valide → octets retournés ──
    public function test_fetch_final_document_accepts_pdf(): void
    {
        config(['signature.external.api_key' => 'sk_test']);
        Http::fake(['*' => Http::response("%PDF-1.4\n signed bytes", 200)]);

        $req = $this->createAndSendExternal();
        $req->metadata = array_merge($req->metadata ?? [], ['final_document_url' => 'https://provider.test/doc/1']);

        $bytes = $this->adapter()->fetchFinalDocument($req);
        $this->assertNotNull($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    // ── 15. Preuve : verifyEvidence faux sans document signé archivé ──
    public function test_verify_evidence_false_without_signed_document(): void
    {
        $req = $this->createAndSendExternal();
        $this->assertFalse($this->adapter()->verifyEvidence($req->fresh()));
    }

    // ── 16. handleWebhookEvent : résolution du requestId via provider_reference ──
    public function test_handle_event_resolves_request_by_provider_reference(): void
    {
        $req = $this->createAndSendExternal();
        $signer = $req->signers()->first();

        $outcome = $this->adapter()->handleWebhookEvent([
            'type' => 'signer.signed',
            'provider_reference' => $req->provider_reference,
            'signer_reference' => $signer->evidence_reference,
        ], []);

        $this->assertTrue($outcome->handled);
        $this->assertSame($req->id, $outcome->signatureRequestId);
        $this->assertSame('signed', $outcome->action);
        $this->assertSame($signer->evidence_reference, $outcome->signerReference);
    }
}
