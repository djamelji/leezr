<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureTokenService;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\ExpireSignatureRequestsUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSignatureAdapter;
use Tests\TestCase;

/**
 * W-SIGNATURE-1 (ADR-651) — matrice fondations : module, permissions, tenant, document émis/non émis,
 * signataires multiples, séquentiel/parallèle, lien sécurisé/expiré/révoqué, double soumission, refus,
 * annulation, expiration, webhook vérifié/invalide/désordre, preuves, empreinte, archivage, copie signée,
 * et l'invariant central : aucun statut « signé » sans preuve vérifiable.
 */
class WorkforceSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private FakeSignatureAdapter $fake;
    private int $employeeId = 4242;
    private int $actorId;

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

        $this->company = Company::create(['name' => 'Sig Co', 'slug' => 'sig-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->actorId = User::factory()->create()->id;

        // Prestataire fictif certifié (webhooks) enregistré sur le manager singleton.
        $this->fake = new FakeSignatureAdapter();
        app(SignatureProviderManager::class)->extend('fake', fn () => $this->fake);
    }

    // ── Helpers ──

    private function user(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-' . uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    private function adminUser(): User
    {
        return $this->user([
            'workforce.view', 'workforce.manage',
            'workforce_signature.view', 'workforce_signature.request', 'workforce_signature.manage',
            'workforce_signature.view_evidence', 'workforce_signature.admin',
        ]);
    }

    private function api(User $u, string $method, string $path, array $body = [], ?Company $company = null)
    {
        $company ??= $this->company;

        return $this->actingAs($u)->json($method, '/api/workforce/signature' . $path, $body, ['X-Company-Id' => $company->id]);
    }

    private function emittedDoc(?string $subjectType = 'employee', ?int $subjectId = null, ?Company $company = null): GeneratedDocument
    {
        $company ??= $this->company;
        $subjectId ??= $this->employeeId;
        $tpl = DocumentTemplate::create([
            'company_id' => $company->id, 'code' => 'tpl-' . uniqid(), 'name' => 'Attestation',
            'subject_type' => $subjectType, 'body_html' => '<p>{{ company.name }}</p>',
            'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $path = 'documents/generated/' . $company->id . '/2026/10/' . uniqid() . '.pdf';
        // Vrai PDF dompdf (FPDI/PAdES peut l'importer) — la finalisation exige pades_embedded par défaut.
        $realPdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Document</h1><p>' . uniqid() . '</p>')->output();
        Storage::disk('local')->put($path, $realPdf);

        return GeneratedDocument::create([
            'company_id' => $company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'generated_by' => $this->actorId,
            'generated_at' => now(), 'file_path' => $path, 'file_size_bytes' => 10,
            'generation_snapshot' => ['x' => 1], 'signature_status' => GeneratedDocument::SIGNATURE_NONE,
        ]);
    }

    /** Crée + envoie une demande (via use cases, provider interne par défaut). */
    private function createAndSend(array $signers, string $order = SignatureRequest::ORDER_SEQUENTIAL, ?string $provider = null, ?string $level = null): SignatureRequest
    {
        $doc = $this->emittedDoc();
        $req = app(CreateSignatureRequestUseCase::class)->execute(
            $this->company->id, $this->actorId, $doc->id, $signers, $level, $order, $provider,
        );

        return app(SendSignatureRequestUseCase::class)->execute($this->company->id, $this->actorId, $req->id);
    }

    /** Invitation manuelle déterministe (retourne le token brut pour piloter le parcours signataire). */
    private function issueToken(SignatureSigner $signer, SignatureRequest $request): string
    {
        $token = SignatureTokenService::generate();
        $signer->token_hash = $token['hash'];
        $signer->token_expires_at = $request->expires_at;
        $signer->token_revoked_at = null;
        $signer->status = SignatureSigner::STATUS_INVITED;
        $signer->invited_at = now();
        $signer->save();

        return $token['raw'];
    }

    private function twoSigners(): array
    {
        return [
            ['role' => SignatureSigner::ROLE_EMPLOYEE, 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1],
            ['role' => SignatureSigner::ROLE_COMPANY_REPRESENTATIVE, 'name' => 'Rep', 'email' => 'rep@x.test', 'order' => 2],
        ];
    }

    // ── 1. Module inactif ──
    public function test_module_inactive_forbidden(): void
    {
        $other = Company::create(['name' => 'No Sig', 'slug' => 'no-sig', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents'] as $m) { // pas workforce_signature
            CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $u = $this->adminUser();
        Membership::create(['user_id' => $u->id, 'company_id' => $other->id, 'role' => 'member', 'company_role_id' => CompanyRole::create(['company_id' => $other->id, 'key' => 'r2-' . uniqid(), 'name' => 'R', 'is_administrative' => true])->id]);
        $this->api($u, 'GET', '/', [], $other)->assertForbidden();
    }

    // ── 2. Permissions ──
    public function test_permissions_enforced(): void
    {
        $viewer = $this->user(['workforce.view', 'workforce_signature.view']);
        $this->api($viewer, 'GET', '/')->assertOk();                 // view OK
        $doc = $this->emittedDoc();
        $this->api($viewer, 'POST', '/', ['generated_document_id' => $doc->id, 'signers' => [['role' => 'employee', 'name' => 'A', 'email' => 'a@x.test']]])
            ->assertForbidden();                                      // request => interdit
        $this->api($viewer, 'GET', '/settings')->assertForbidden();  // admin => interdit
    }

    // ── 3. Isolation tenant ──
    public function test_tenant_isolation(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $other = Company::create(['name' => 'B', 'slug' => 'b-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $ub = User::factory()->create();
        $roleB = CompanyRole::create(['company_id' => $other->id, 'key' => 'rb-' . uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $roleB->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage', 'workforce_signature.view'])->pluck('id')->toArray());
        Membership::create(['user_id' => $ub->id, 'company_id' => $other->id, 'role' => 'member', 'company_role_id' => $roleB->id]);
        // B a les droits Signature mais ne voit JAMAIS la demande de A → 404 (isolation des données).
        $this->api($ub, 'GET', '/' . $req->id, [], $other)->assertNotFound();
    }

    // ── 4/6/7. Document émis + création + signataires multiples ──
    public function test_create_request_on_emitted_document(): void
    {
        $doc = $this->emittedDoc();
        $res = $this->api($this->adminUser(), 'POST', '/', [
            'generated_document_id' => $doc->id,
            'signers' => $this->twoSigners(),
        ])->assertCreated();

        $this->assertSame(SignatureRequest::STATUS_PENDING, $res->json('status'));
        $this->assertCount(2, $res->json('signers'));
        $this->assertNotEmpty(SignatureRequest::first()->document_sha256); // empreinte figée (§22)
    }

    // ── 5. Document non émis refusé ──
    public function test_non_emitted_document_refused(): void
    {
        $this->api($this->adminUser(), 'POST', '/', [
            'generated_document_id' => 999999, // aucun GeneratedDocument => jamais émis
            'signers' => [['role' => 'employee', 'name' => 'A', 'email' => 'a@x.test']],
        ])->assertNotFound();
    }

    // ── 8. Séquentiel : seul le 1er est invité, le 2e ne peut pas agir avant son tour ──
    public function test_sequential_only_first_invited_and_order_enforced(): void
    {
        $req = $this->createAndSend($this->twoSigners(), SignatureRequest::ORDER_SEQUENTIAL);
        $signers = $req->signers()->orderBy('order')->get();
        $this->assertSame(SignatureSigner::STATUS_INVITED, $signers[0]->status);
        $this->assertSame(SignatureSigner::STATUS_PENDING, $signers[1]->status);

        // Donner un token au 2e (hors tour) et tenter de signer → refus séquence.
        $raw2 = $this->issueToken($signers[1], $req);
        $this->postJson('/api/public/signature/' . $raw2 . '/sign')->assertStatus(422);
    }

    // ── 9. Parallèle : tous invités ──
    public function test_parallel_all_invited(): void
    {
        $req = $this->createAndSend($this->twoSigners(), SignatureRequest::ORDER_PARALLEL);
        foreach ($req->signers as $s) {
            $this->assertSame(SignatureSigner::STATUS_INVITED, $s->status);
        }
    }

    // ── Téléchargement du document signé : authentifié, PDF, scope, 404 (§9 correctif) ──
    public function test_download_signed_document_authenticated_pdf(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();
        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);

        $res = $this->api($this->adminUser(), 'GET', '/' . $req->id . '/download')->assertOk();
        $this->assertStringContainsString('pdf', strtolower($res->headers->get('content-type') ?? ''));
        $this->assertStringStartsWith('%PDF', $res->streamedContent());
    }

    public function test_download_no_signed_document_404(): void
    {
        $req = $this->createAndSend($this->twoSigners()); // pending, pas de doc signé
        $this->api($this->adminUser(), 'GET', '/' . $req->id . '/download')->assertNotFound();
    }

    public function test_download_cross_tenant_404(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $raw = $this->issueToken($req->signers()->first(), $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();

        // Company B (module actif, droits OK) ne peut pas télécharger le doc signé de A.
        $other = Company::create(['name' => 'DL B', 'slug' => 'dl-b', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $ub = User::factory()->create();
        $roleB = CompanyRole::create(['company_id' => $other->id, 'key' => 'dlb-' . uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $roleB->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage', 'workforce_signature.view'])->pluck('id')->toArray());
        Membership::create(['user_id' => $ub->id, 'company_id' => $other->id, 'role' => 'member', 'company_role_id' => $roleB->id]);
        $this->api($ub, 'GET', '/' . $req->id . '/download', [], $other)->assertNotFound();
    }

    // ── 10. Invitation sécurisée : hash stocké (jamais le token brut) + show public ──
    public function test_secure_invitation_token_hashed(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $signer = $req->signers()->orderBy('order')->first();
        $this->assertNotNull($signer->token_hash);
        $this->assertSame(64, strlen($signer->token_hash)); // sha256 hex

        $raw = $this->issueToken($signer, $req);
        $this->getJson('/api/public/signature/' . $raw)->assertOk()->assertJsonPath('can_act', true);
        $this->getJson('/api/public/signature/deadbeefdeadbeef')->assertNotFound(); // anti-énumération
    }

    // ── 11. Lien expiré ──
    public function test_expired_link_rejected(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $signer = $req->signers()->orderBy('order')->first();
        $raw = $this->issueToken($signer, $req);
        $req->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertStatus(422);
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $req->fresh()->status);
    }

    // ── 12. Lien révoqué (annulation) ──
    public function test_revoked_link_rejected(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $signer = $req->signers()->orderBy('order')->first();
        $raw = $this->issueToken($signer, $req);
        app(\App\Modules\Workforce\Signature\UseCases\CancelSignatureRequestUseCase::class)->execute($this->company->id, $this->actorId, $req->id);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertStatus(422);
    }

    // ── 13/17. Double soumission / concurrence ──
    public function test_double_submission_rejected(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'A', 'email' => 'a@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();
        // Le lien est à usage unique → 2e soumission rejetée.
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertStatus(422);
    }

    // ── 14. Refus ──
    public function test_decline_makes_request_terminal(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $signer = $req->signers()->orderBy('order')->first();
        $raw = $this->issueToken($signer, $req);
        $this->postJson('/api/public/signature/' . $raw . '/decline', ['reason' => 'nope'])->assertOk();
        $this->assertSame(SignatureRequest::STATUS_DECLINED, $req->fresh()->status);
    }

    // ── 15. Annulation ──
    public function test_cancel_active_request(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $this->api($this->adminUser(), 'POST', '/' . $req->id . '/cancel', ['reason' => 'stop'])->assertOk()
            ->assertJsonPath('status', SignatureRequest::STATUS_CANCELLED);
    }

    // ── 16. Expiration (use case) ──
    public function test_expiration_use_case(): void
    {
        $req = $this->createAndSend($this->twoSigners());
        $req->update(['expires_at' => now()->subDay()]);
        $count = app(ExpireSignatureRequestsUseCase::class)->execute($this->company->id);
        $this->assertSame(1, $count);
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $req->fresh()->status);
    }

    // ── 21/23/24. Signature complète : preuves + archivage + copie signée + doc signed ──
    public function test_full_completion_archives_and_marks_signed(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();

        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);
        $this->assertNotNull($req->signed_document_path);
        Storage::disk('local')->assertExists($req->signed_document_path);          // §23 archivage
        $this->assertSame(GeneratedDocument::SIGNATURE_SIGNED, $req->document->fresh()->signature_status);

        // §21 preuves : la chaîne d'événements existe.
        $types = $req->events()->pluck('type')->all();
        foreach (['created', 'sent', 'invited', 'signed', 'verification_passed', 'completed'] as $t) {
            $this->assertContains($t, $types, "événement {$t} journalisé");
        }
        // §24 copie signée envoyée au collaborateur.
        Notification::assertSentOnDemand(\App\Notifications\Signature\SignedCopyNotification::class);
    }

    // ── W-SIGNATURE-3 : vrai PDF → signature PAdES EMBARQUÉE + vérification indépendante pdfsig ──
    public function test_real_pdf_finalization_produces_embedded_pades(): void
    {
        if (! app(\App\Core\Crypto\NativePadesSignatureService::class)->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        // Document source = vrai PDF dompdf (FPDI peut l'importer, contrairement aux fixtures factices).
        $realPdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat réel</h1><p>Test PAdES.</p>')->output();
        $doc = $this->emittedDoc();
        Storage::disk('local')->put($doc->file_path, $realPdf);

        $req = app(CreateSignatureRequestUseCase::class)->execute($this->company->id, $this->actorId, $doc->id, [['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]], null, 'sequential', null);
        app(SendSignatureRequestUseCase::class)->execute($this->company->id, $this->actorId, $req->id);
        $raw = $this->issueToken($req->signers()->first(), $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();

        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);
        $this->assertSame('pades_embedded', $req->metadata['signature_format'] ?? null, 'format PAdES embarqué');

        // Le fichier archivé est un PDF avec signature embarquée.
        $signedBytes = Storage::disk('local')->get($req->signed_document_path);
        $this->assertStringStartsWith('%PDF', $signedBytes);

        // Endpoint verify : signature embarquée vérifiée indépendamment par pdfsig.
        $res = $this->api($this->adminUser(), 'GET', '/' . $req->id . '/verify')->assertOk();
        $res->assertJsonPath('format', 'pades_embedded')
            ->assertJsonPath('embedded_signature', true)
            ->assertJsonPath('verified_by', 'pdfsig')
            ->assertJsonPath('all_signatures_valid', true)
            ->assertJsonPath('certificate.recognized_trust_chain', false) // auto-signé, honnête
            ->assertJsonPath('trust.eidas_advanced', 'not_demonstrated');
    }

    // ── W-SIGNATURE-4 : signature PERSONNELLE contrôlée par le signataire (WebCrypto ECDSA) ──
    private function ecKeypair(): array
    {
        $pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pub = openssl_pkey_get_details($pkey)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pub));

        return [base64_encode($der), $pkey];
    }

    private function signP1363(string $payload, $priv): string
    {
        openssl_sign($payload, $der, $priv, OPENSSL_ALGO_SHA256);
        $off = 2;
        $rLen = ord($der[$off + 1]);
        $r = substr($der, $off + 2, $rLen);
        $sStart = $off + 2 + $rLen;
        $sLen = ord($der[$sStart + 1]);
        $s = substr($der, $sStart + 2, $sLen);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return base64_encode($r . $s);
    }

    public function test_personal_signature_verified_and_recorded(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);

        // 1. Le signataire ouvre le parcours → challenge + claims canoniques émis.
        $show = $this->getJson('/api/public/signature/' . $raw)->assertOk();
        $claims = $show->json('personal_signing.claims');
        $this->assertNotEmpty($claims['challenge']);
        $this->assertNotEmpty($claims['document_sha256']);

        // 2. Signature PERSONNELLE côté "navigateur" (clé du signataire).
        [$spki, $priv] = $this->ecKeypair();
        $payload = app(\App\Core\Crypto\PersonalSignatureService::class)->canonicalPayload($claims);
        $sig = $this->signP1363($payload, $priv);

        // 3. Soumission → vérification serveur + preuve stockée.
        $this->postJson('/api/public/signature/' . $raw . '/sign', ['public_key_spki' => $spki, 'signature_p1363' => $sig])->assertOk();

        $signer->refresh();
        $this->assertSame(SignatureSigner::STATUS_SIGNED, $signer->status);
        $this->assertSame('webcrypto_ecdsa_p256', $signer->auth_method, 'méthode = signature personnelle');
        $this->assertNotNull($signer->public_key_id, 'clé publique enregistrée');
        $this->assertNotNull($signer->personal_signature, 'preuve individuelle conservée');
        $this->assertNotNull($signer->challenge_used_at, 'challenge consommé (anti-rejeu)');

        // L'endpoint /verify re-vérifie INDÉPENDAMMENT la signature personnelle (distincte du cachet serveur).
        $req->refresh();
        $res = $this->api($this->adminUser(), 'GET', '/' . $req->id . '/verify')->assertOk();
        $res->assertJsonPath('personal_signatures.0.verified', true)
            ->assertJsonPath('personal_signatures.0.signer_controlled_key', true)
            ->assertJsonPath('personal_signatures.0.algorithm', \App\Core\Crypto\PersonalSignatureService::ALGORITHM);
    }

    public function test_invalid_personal_signature_rejected_no_transition(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        $this->getJson('/api/public/signature/' . $raw)->assertOk();

        [$spki] = $this->ecKeypair();
        // Signature bidon → rejet, aucune transition réussie.
        $this->postJson('/api/public/signature/' . $raw . '/sign', ['public_key_spki' => $spki, 'signature_p1363' => base64_encode(str_repeat("\x01", 64))])->assertStatus(422);

        $this->assertSame(SignatureSigner::STATUS_INVITED, $signer->fresh()->status, 'signataire NON signé');
        $this->assertSame(SignatureRequest::STATUS_PENDING, $req->fresh()->status);
    }

    // ── Finalisation : artefact cryptographique vérifié + rapport honnête (défaut = PAdES embarqué) ──
    public function test_completed_request_has_verified_crypto_artifact_and_honest_report(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $raw = $this->issueToken($req->signers()->first(), $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();
        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);
        $this->assertSame('pades_embedded', $req->metadata['signature_format'] ?? null);

        $res = $this->api($this->adminUser(), 'GET', '/' . $req->id . '/verify')->assertOk();
        $res->assertJsonPath('overall_valid', true)
            ->assertJsonPath('document_intact', true)
            // Honnêteté : certificat NON reconnu, cachet serveur, identité non liée crypto, eIDAS non démontré.
            ->assertJsonPath('certificate.recognized_trust_chain', false)
            ->assertJsonPath('trust.seal_type', 'organizational_server_seal')
            ->assertJsonPath('trust.signer_identity_cryptographically_bound', false)
            ->assertJsonPath('trust.eidas_advanced', 'not_demonstrated');
    }

    public function test_verify_detects_tampered_signed_document(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $raw = $this->issueToken($req->signers()->first(), $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();
        $req->refresh();

        // Altération du document final APRÈS cachet.
        Storage::disk('local')->put($req->signed_document_path, '%PDF-ALTERED-AFTER-SEAL');
        $res = $this->api($this->adminUser(), 'GET', '/' . $req->id . '/verify')->assertOk();
        $res->assertJsonPath('document_intact', false)->assertJsonPath('overall_valid', false);
    }

    // ── 22/27. Empreinte altérée → échec, aucun statut signé sans preuve ──
    public function test_tampered_source_fails_finalization(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        // Altère le document source APRÈS la demande (empreinte ne correspondra plus).
        Storage::disk('local')->put($req->document->file_path, '%PDF-TAMPERED-CONTENT');
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();

        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_FAILED, $req->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $req->document->fresh()->signature_status);
    }

    // ── 27. verifyEvidence=false → aucune complétion ──
    public function test_unverifiable_evidence_blocks_completion(): void
    {
        $this->fake->evidenceValid = false;
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]], SignatureRequest::ORDER_SEQUENTIAL, FakeSignatureAdapter::KEY, SignatureRequest::LEVEL_QUALIFIED);
        $signer = $req->signers()->first();
        $raw = $this->issueToken($signer, $req);
        $this->postJson('/api/public/signature/' . $raw . '/sign')->assertOk();

        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_FAILED, $req->status);
        $this->assertNotSame(GeneratedDocument::SIGNATURE_SIGNED, $req->document->fresh()->signature_status);
    }

    // ── Niveau non supporté par le prestataire (interne ne fait que simple) ──
    public function test_unsupported_level_rejected(): void
    {
        $doc = $this->emittedDoc();
        $this->api($this->adminUser(), 'POST', '/', [
            'generated_document_id' => $doc->id,
            'signers' => [['role' => 'employee', 'name' => 'A', 'email' => 'a@x.test']],
            'signature_level' => SignatureRequest::LEVEL_QUALIFIED, // interne ne supporte pas
        ])->assertStatus(422);
    }

    // ── 18/19/20. Webhooks : vérifié, invalide, désordre ──
    public function test_webhook_invalid_signature_rejected(): void
    {
        $this->postJson('/api/webhooks/signatures/fake', ['id' => 'e1', 'type' => 'signer.signed'])
            ->assertStatus(400); // pas d'en-tête X-Fake-Signature: valid
    }

    public function test_webhook_verified_drives_signature(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]], SignatureRequest::ORDER_SEQUENTIAL, FakeSignatureAdapter::KEY, SignatureRequest::LEVEL_ADVANCED);
        $signer = $req->signers()->first();

        $payload = ['id' => 'evt-1', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference];
        $this->call('POST', '/api/webhooks/signatures/fake', [], [], [], ['HTTP_X-Fake-Signature' => 'valid', 'CONTENT_TYPE' => 'application/json'], json_encode($payload))
            ->assertOk();

        $this->assertSame(SignatureSigner::STATUS_SIGNED, $signer->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->fresh()->status);

        // §18 idempotence : rejouer le même event_id ne double rien.
        $this->call('POST', '/api/webhooks/signatures/fake', [], [], [], ['HTTP_X-Fake-Signature' => 'valid', 'CONTENT_TYPE' => 'application/json'], json_encode($payload))
            ->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_webhook_out_of_order_does_not_corrupt_state(): void
    {
        $req = $this->createAndSend([['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]], SignatureRequest::ORDER_SEQUENTIAL, FakeSignatureAdapter::KEY, SignatureRequest::LEVEL_ADVANCED);
        $signer = $req->signers()->first();
        // Event « completed » déjà signé : un 2e event signed (ref identique) avec autre id ne régresse rien.
        $p1 = json_encode(['id' => 'a', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference]);
        $this->call('POST', '/api/webhooks/signatures/fake', [], [], [], ['HTTP_X-Fake-Signature' => 'valid', 'CONTENT_TYPE' => 'application/json'], $p1)->assertOk();
        $p2 = json_encode(['id' => 'b', 'type' => 'signer.signed', 'signer_reference' => $signer->evidence_reference]);
        $this->call('POST', '/api/webhooks/signatures/fake', [], [], [], ['HTTP_X-Fake-Signature' => 'valid', 'CONTENT_TYPE' => 'application/json'], $p2)->assertOk();
        // Toujours completed, pas d'exception/corruption.
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->fresh()->status);
    }

    // ── Settings + copie signée désactivable ──
    public function test_settings_update_and_signed_copy_toggle(): void
    {
        $admin = $this->adminUser();
        $this->api($admin, 'PUT', '/settings', ['send_signed_copy_to_employee' => false, 'validity_days' => 10])->assertOk()
            ->assertJsonPath('send_signed_copy_to_employee', false)
            ->assertJsonPath('validity_days', 10);
    }

    // ── W-SIGNATURE-12 : supervision + registre certificats (gated, scoped, non sensibles) ──
    public function test_supervision_metrics_gated_and_scoped(): void
    {
        $admin = $this->adminUser(); // possède view_evidence
        $this->api($admin, 'GET', '/supervision')->assertOk()
            ->assertJsonStructure(['requests' => ['pending', 'expired', 'failed', 'completed'], 'operational' => ['needs_reconciliation', 'expired_preparations'], 'certificates']);

        // Un simple lecteur (sans view_evidence) ne peut PAS accéder à la supervision.
        $viewer = $this->user(['workforce.view', 'workforce_signature.view']);
        $this->api($viewer, 'GET', '/supervision')->assertForbidden();
    }

    public function test_certificates_registry_never_exposes_private_key(): void
    {
        $admin = $this->adminUser();
        $resp = $this->api($admin, 'GET', '/certificates')->assertOk()->assertJsonStructure(['data']);
        $this->assertStringNotContainsString('PRIVATE KEY', $resp->getContent());

        $viewer = $this->user(['workforce.view', 'workforce_signature.view']);
        $this->api($viewer, 'GET', '/certificates')->assertForbidden();
    }
}
