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
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\Adapters\ExternalSignatureAdapter;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-767 — external signature provider: the REAL completion path (webhooks → sealed document fetched
 * from the provider → evidence verified on that artefact → completed), plus scheduled expiry.
 *
 * Before ADR-767 an external request could NEVER complete: `verifyEvidence()` ran before the sealed
 * document existed and the `final_document_url` sent by the provider was never persisted.
 *
 * SCOPE: the provider is simulated with Http::fake (generic schema). No real provider environment is
 * reached — eIDAS validity and a real vendor's API remain UNVERIFIED (NO-GO PROD unchanged).
 */
class Adr767SignaturePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $actorId;
    private string $secret = 'whsec_adr767_test_only';
    private string $sealedPdf;

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

        $this->company = $this->makeCompany('adr767-sig-a');
        $this->actorId = User::factory()->create()->id;
        $this->sealedPdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Sealed by provider</h1>')->output();

        config(['signature.external.webhook_secret' => $this->secret, 'signature.external.replay_tolerance_seconds' => 300]);
    }

    // ── Helpers ──

    private function makeCompany(string $slug): Company
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $company;
    }

    /** Real provider credentials + a simulated provider API (envelope creation + sealed document). */
    private function withRealProvider(?string $finalBody = null, int $finalStatus = 200): void
    {
        config(['signature.external.api_base' => 'https://provider.test/v1', 'signature.external.api_key' => 'sk_test_adr767']);
        $finalBody ??= $this->sealedPdf;
        Http::fake([
            'provider.test/v1/envelopes' => function ($request) {
                $signers = [];
                foreach ($request->data()['signers'] as $s) {
                    $signers[$s['id']] = 'sg_'.$request->data()['external_id'].'_'.$s['id'];
                }

                return Http::response(['id' => 'env_'.$request->data()['external_id'], 'signers' => $signers], 201);
            },
            'provider.test/v1/documents/*' => Http::response($finalBody, $finalStatus),
            '*' => Http::response('unexpected host', 500),
        ]);
    }

    private function emittedDoc(Company $company): GeneratedDocument
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $company->id, 'code' => 'tpl-'.uniqid(), 'name' => 'Attestation',
            'subject_type' => 'employee', 'body_html' => '<p>{{ company.name }}</p>',
            'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $path = 'documents/generated/'.$company->id.'/2026/11/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Doc</h1><p>'.uniqid().'</p>')->output());

        return GeneratedDocument::create([
            'company_id' => $company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'employee', 'subject_id' => 4242, 'generated_by' => $this->actorId,
            'generated_at' => now(), 'file_path' => $path, 'file_size_bytes' => 10,
            'generation_snapshot' => ['x' => 1], 'signature_status' => GeneratedDocument::SIGNATURE_NONE,
        ]);
    }

    private function createAndSend(?Company $company = null, int $signers = 1, ?string $expiresAt = null): SignatureRequest
    {
        $company ??= $this->company;
        $doc = $this->emittedDoc($company);
        $list = [['role' => SignatureSigner::ROLE_EMPLOYEE, 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]];
        if ($signers > 1) {
            $list[] = ['role' => SignatureSigner::ROLE_COMPANY_REPRESENTATIVE, 'name' => 'Boss', 'email' => 'boss@x.test', 'order' => 2];
        }
        $req = app(CreateSignatureRequestUseCase::class)->execute(
            $company->id, $this->actorId, $doc->id, $list,
            SignatureRequest::LEVEL_SIMPLE, SignatureRequest::ORDER_PARALLEL, ExternalSignatureAdapter::KEY,
        );
        $req = app(SendSignatureRequestUseCase::class)->execute($company->id, $this->actorId, $req->id);
        if ($expiresAt !== null) {
            SignatureRequest::withoutGlobalScopes()->where('id', $req->id)->update(['expires_at' => $expiresAt]);
        }

        return $req->fresh('signers');
    }

    private function webhook(array $payload)
    {
        $raw = json_encode($payload);
        $ts = now()->getTimestamp();

        return $this->call('POST', '/api/webhooks/signatures/external', [], [], [], [
            'HTTP_X-Signature-Timestamp' => (string) $ts,
            'HTTP_X-Signature' => hash_hmac('sha256', $ts.'.'.$raw, $this->secret),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function fresh(SignatureRequest $r): SignatureRequest
    {
        return SignatureRequest::withoutGlobalScopes()->findOrFail($r->id);
    }

    private function doc(SignatureRequest $r): GeneratedDocument
    {
        return GeneratedDocument::withoutGlobalScopes()->findOrFail($r->generated_document_id);
    }

    private function failureReasons(SignatureRequest $r): array
    {
        return SignatureEvent::where('signature_request_id', $r->id)->where('type', SignatureEvent::TYPE_VERIFICATION_FAILED)
            ->get()->map(fn ($e) => $e->payload['reason'] ?? null)->all();
    }

    // ───────── §7 : the real completion path ─────────

    public function test_real_provider_flow_completes_with_the_sealed_document_returned_by_the_provider(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend(signers: 2);
        [$s1, $s2] = $req->signers->all();
        $this->assertSame('env_'.$req->id, $req->provider_reference);

        // Each signer signs at the provider.
        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'provider_reference' => $req->provider_reference, 'signer_reference' => $s1->evidence_reference])->assertOk();
        $this->assertSame(SignatureRequest::STATUS_PARTIALLY_SIGNED, $this->fresh($req)->status);
        $this->webhook(['id' => 'e2', 'type' => 'signer.signed', 'provider_reference' => $req->provider_reference, 'signer_reference' => $s2->evidence_reference])->assertOk();

        // Everyone signed but the provider has not delivered the sealed document yet:
        // neither completed nor failed — the request waits for the provider's completion event.
        $waiting = $this->fresh($req);
        $this->assertTrue($waiting->isActive(), 'Must wait for the sealed document, got: '.$waiting->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);

        // The provider announces completion with the sealed document location (+ its hash).
        $this->webhook([
            'id' => 'e3', 'type' => 'envelope.completed', 'provider_reference' => $req->provider_reference,
            'final_document_url' => 'https://provider.test/v1/documents/'.$req->id,
            'final_document_sha256' => hash('sha256', $this->sealedPdf),
        ])->assertOk();

        $done = $this->fresh($req);
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $done->status);
        $this->assertNotNull($done->signed_document_path);
        $this->assertTrue(Storage::disk('local')->exists($done->signed_document_path));
        // The archived artefact IS the provider's document, byte for byte.
        $this->assertSame($this->sealedPdf, Storage::disk('local')->get($done->signed_document_path));
        $this->assertSame(hash('sha256', $this->sealedPdf), $done->signed_document_sha256);
        $this->assertSame('provider_sealed', $done->metadata['signature_format'] ?? null);
        $this->assertFalse($done->metadata['certified'] ?? true, 'Never certified unless explicitly configured.');
        $this->assertStringStartsWith("documents/signed/{$this->company->id}/", $done->signed_document_path);

        $doc = $this->doc($req);
        $this->assertSame(GeneratedDocument::SIGNATURE_SIGNED, $doc->signature_status);
        $this->assertSame($done->signed_document_sha256, $doc->signature_metadata['signed_document_sha256']);
        // The original is preserved untouched.
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));

        // The sealed document was fetched with the provider credentials.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/documents/'.$req->id) && $r->hasHeader('Authorization', 'Bearer sk_test_adr767'));

        // Replaying the completion changes nothing.
        $this->webhook(['id' => 'e4', 'type' => 'envelope.completed', 'provider_reference' => $req->provider_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$req->id])->assertOk();
        $this->assertSame($done->signed_document_path, $this->fresh($req)->signed_document_path);
    }

    public function test_last_signer_event_carrying_the_sealed_document_completes_directly(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();
        $signer = $req->signers->first();

        $this->webhook([
            'id' => 'e1', 'type' => 'signer.signed', 'provider_reference' => $req->provider_reference,
            'signer_reference' => $signer->evidence_reference,
            'final_document_url' => 'https://provider.test/v1/documents/'.$req->id,
        ])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $this->fresh($req)->status);
        $this->assertSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    public function test_completion_event_before_all_signers_signed_does_not_complete(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend(signers: 2);
        [$s1] = $req->signers->all();
        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'provider_reference' => $req->provider_reference, 'signer_reference' => $s1->evidence_reference])->assertOk();

        $this->webhook(['id' => 'e2', 'type' => 'envelope.completed', 'provider_reference' => $req->provider_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$req->id])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_PARTIALLY_SIGNED, $this->fresh($req)->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    // ───────── controls are NOT relaxed ─────────

    public function test_non_pdf_document_from_provider_fails_and_never_signs(): void
    {
        $this->withRealProvider('<html>error page</html>');
        $req = $this->createAndSend();
        $signer = $req->signers->first();

        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference, 'provider_reference' => $req->provider_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$req->id])->assertOk();

        $failed = $this->fresh($req);
        $this->assertSame(SignatureRequest::STATUS_FAILED, $failed->status);
        $this->assertNull($failed->signed_document_path);
        $this->assertContains('provider_evidence_unverified', $this->failureReasons($req));
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    public function test_sandbox_without_credentials_still_fails_evidence_verification(): void
    {
        // No api_base / api_key → sandbox. Nothing can ever be fetched → never completed.
        Http::fake();
        $req = $this->createAndSend();
        $signer = $req->signers->first();
        $this->assertStringStartsWith('ext-sandbox-', $req->provider_reference);

        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference, 'final_document_url' => 'https://provider.test/v1/documents/1'])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_FAILED, $this->fresh($req)->status);
        $this->assertContains('provider_evidence_unverified', $this->failureReasons($req));
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
        Http::assertNothingSent();
    }

    public function test_tampered_source_document_fails_even_with_a_valid_sealed_document(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();
        $signer = $req->signers->first();

        // The source changes after the request froze its fingerprint.
        Storage::disk('local')->put($this->doc($req)->file_path, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Altered</h1>')->output());

        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference, 'provider_reference' => $req->provider_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$req->id])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_FAILED, $this->fresh($req)->status);
        $this->assertContains('document_fingerprint_mismatch', $this->failureReasons($req));
        $this->assertNull($this->fresh($req)->signed_document_path);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    public function test_sealed_document_not_matching_the_hash_attested_by_the_provider_fails(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();
        $signer = $req->signers->first();

        $this->webhook([
            'id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference, 'provider_reference' => $req->provider_reference,
            'final_document_url' => 'https://provider.test/v1/documents/'.$req->id,
            'final_document_sha256' => str_repeat('a', 64),
        ])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_FAILED, $this->fresh($req)->status);
        $this->assertContains('provider_document_hash_mismatch', $this->failureReasons($req));
        $this->assertNull($this->fresh($req)->signed_document_path);
    }

    public function test_sealed_document_url_outside_the_provider_api_is_never_fetched(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();
        $signer = $req->signers->first();

        // A URL on another host must never receive the provider API token.
        $this->webhook(['id' => 'e1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference, 'provider_reference' => $req->provider_reference, 'final_document_url' => 'https://evil.test/steal'])->assertOk();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test'));
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $this->fresh($req)->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    public function test_provider_failure_event_fails_the_request(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();

        $this->webhook(['id' => 'e1', 'type' => 'envelope.failed', 'provider_reference' => $req->provider_reference, 'reason' => 'provider outage'])->assertOk();

        $this->assertSame(SignatureRequest::STATUS_FAILED, $this->fresh($req)->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($req)->signature_status);
    }

    // ───────── P3-c/d : webhook reference resolution & tenant isolation ─────────

    public function test_signer_reference_is_resolved_within_its_own_request_only(): void
    {
        $this->withRealProvider();
        $other = $this->makeCompany('adr767-sig-b');
        $mine = $this->createAndSend($this->company);
        $theirs = $this->createAndSend($other);
        $mySigner = $mine->signers->first();
        $theirSigner = $theirs->signers->first();

        // Our envelope reference + THEIR signer reference → inconsistent → no effect on anyone.
        $this->webhook(['id' => 'x1', 'type' => 'signer.signed', 'provider_reference' => $mine->provider_reference, 'signer_reference' => $theirSigner->evidence_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$mine->id])->assertOk();
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $theirSigner->fresh()->status);
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $mySigner->fresh()->status);
        $this->assertTrue($this->fresh($mine)->isActive());
        $this->assertTrue($this->fresh($theirs)->isActive());

        // Their completion event never completes OUR request.
        $this->webhook(['id' => 'x2', 'type' => 'envelope.completed', 'provider_reference' => $theirs->provider_reference, 'final_document_url' => 'https://provider.test/v1/documents/'.$theirs->id])->assertOk();
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $this->fresh($mine)->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $this->doc($mine)->signature_status);

        // A consistent event signs exactly one signer, in its own tenant.
        $this->webhook(['id' => 'x3', 'type' => 'signer.signed', 'provider_reference' => $theirs->provider_reference, 'signer_reference' => $theirSigner->evidence_reference])->assertOk();
        $this->assertSame(SignatureSigner::STATUS_SIGNED, $theirSigner->fresh()->status);
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $mySigner->fresh()->status);
    }

    public function test_ambiguous_signer_reference_has_no_effect(): void
    {
        $this->withRealProvider();
        $other = $this->makeCompany('adr767-sig-c');
        $a = $this->createAndSend($this->company);
        $b = $this->createAndSend($other);
        // Same signer reference in two requests of two tenants (a provider reusing ids, or forged data).
        SignatureSigner::withoutGlobalScopes()->whereIn('signature_request_id', [$a->id, $b->id])->update(['evidence_reference' => 'dup-ref']);

        // Without the envelope reference the event is ambiguous → refused (no signer touched).
        $this->webhook(['id' => 'a1', 'type' => 'signer.signed', 'signer_reference' => 'dup-ref'])->assertOk();
        $this->assertSame(0, SignatureSigner::withoutGlobalScopes()->where('status', SignatureSigner::STATUS_SIGNED)->count());

        // With it, exactly the right one.
        $this->webhook(['id' => 'a2', 'type' => 'signer.signed', 'provider_reference' => $a->provider_reference, 'signer_reference' => 'dup-ref'])->assertOk();
        $this->assertSame(SignatureSigner::STATUS_SIGNED, $a->signers->first()->fresh()->status);
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $b->signers->first()->fresh()->status);
    }

    public function test_reference_of_a_request_handled_by_another_provider_is_ignored(): void
    {
        $this->withRealProvider();
        $req = $this->createAndSend();
        $signer = $req->signers->first();
        // Same reference, but the request belongs to another provider.
        SignatureRequest::withoutGlobalScopes()->where('id', $req->id)->update(['provider' => 'internal']);

        $this->webhook(['id' => 'p1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference])->assertOk();

        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
    }

    // ───────── §8 : scheduled expiry ─────────

    public function test_expiry_command_expires_due_requests_only_and_is_idempotent(): void
    {
        Http::fake();
        $other = $this->makeCompany('adr767-sig-d');

        $dueA = $this->createAndSend($this->company, expiresAt: now()->subDay()->toDateTimeString());
        $dueB = $this->createAndSend($other, expiresAt: now()->subHour()->toDateTimeString());
        $notDue = $this->createAndSend($this->company, expiresAt: now()->addDay()->toDateTimeString());
        $cancelled = $this->createAndSend($this->company, expiresAt: now()->subDay()->toDateTimeString());
        SignatureRequest::withoutGlobalScopes()->where('id', $cancelled->id)->update(['status' => SignatureRequest::STATUS_CANCELLED]);
        $completed = $this->createAndSend($this->company, expiresAt: now()->subDay()->toDateTimeString());
        SignatureRequest::withoutGlobalScopes()->where('id', $completed->id)->update(['status' => SignatureRequest::STATUS_COMPLETED]);

        // Job context: no HTTP request, no tenant bound — every tenant must still be processed.
        $this->artisan('workforce:signature-expire')->assertSuccessful();

        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $this->fresh($dueA)->status);
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $this->fresh($dueB)->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $this->fresh($notDue)->status);
        $this->assertSame(SignatureRequest::STATUS_CANCELLED, $this->fresh($cancelled)->status);
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $this->fresh($completed)->status);

        // Signing links of an expired request are revoked; the others are untouched.
        $this->assertNotNull($dueA->signers->first()->fresh()->token_revoked_at);
        $this->assertNull($notDue->signers->first()->fresh()->token_revoked_at);

        // The event is written in the request's OWN tenant.
        $event = SignatureEvent::where('signature_request_id', $dueB->id)->where('type', SignatureEvent::TYPE_EXPIRED)->first();
        $this->assertNotNull($event);
        $this->assertSame($other->id, (int) $event->company_id);

        $this->assertSame(2, app(\App\Modules\Workforce\Signature\UseCases\ExpireSignatureRequestsUseCase::class)->execute() + 2, 'Second pass expires nothing.');

        // Re-run: nothing changes, nothing is duplicated.
        $events = SignatureEvent::where('type', SignatureEvent::TYPE_EXPIRED)->count();
        $this->artisan('workforce:signature-expire')->assertSuccessful();
        $this->assertSame($events, SignatureEvent::where('type', SignatureEvent::TYPE_EXPIRED)->count());
        $this->assertSame(SignatureRequest::STATUS_PENDING, $this->fresh($notDue)->status);

        // An expired request can no longer be signed through a late webhook.
        $signer = $dueA->signers->first();
        $this->webhook(['id' => 'late', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference])->assertOk();
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $this->fresh($dueA)->status);
    }

    public function test_expiry_runs_under_strict_tenancy_without_any_bound_tenant(): void
    {
        Http::fake();
        $other = $this->makeCompany('adr767-sig-e');
        $a = $this->createAndSend($this->company, expiresAt: now()->subDay()->toDateTimeString());
        $b = $this->createAndSend($other, expiresAt: now()->subDay()->toDateTimeString());

        // Strict tenancy: any tenant-scoped query without a context throws (ADR-733).
        \App\Core\Tenancy\CompanyContext::$strict = true;
        try {
            $this->artisan('workforce:signature-expire')->assertSuccessful();
        } finally {
            \App\Core\Tenancy\CompanyContext::$strict = false;
        }

        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $this->fresh($a)->status);
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $this->fresh($b)->status);
        $this->assertFalse(\App\Core\Tenancy\CompanyContext::has(), 'No tenant context may leak out of the job.');
    }

    public function test_expiry_dry_run_changes_nothing(): void
    {
        Http::fake();
        $due = $this->createAndSend($this->company, expiresAt: now()->subDay()->toDateTimeString());

        $this->artisan('workforce:signature-expire --dry-run')->assertSuccessful();

        $this->assertSame(SignatureRequest::STATUS_PENDING, $this->fresh($due)->status);
    }

    public function test_expiry_is_wired_to_the_scheduler_and_the_cockpit_registry(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'workforce:signature-expire'));
        $this->assertCount(1, $events, 'workforce:signature-expire must be scheduled exactly once.');
        $this->assertTrue($events->first()->withoutOverlapping, 'Must not overlap.');

        $this->assertArrayHasKey('workforce:signature-expire', \App\Core\Automation\ScheduledTaskRegistry::all());
    }
}
