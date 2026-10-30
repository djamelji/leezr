<?php

namespace Tests\Feature;

use App\Core\Crypto\NativePadesSignatureService;
use App\Core\Crypto\PersonalSignatureService;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Modules\Workforce\Signature\Models\SignatureCertificate;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Services\SignatureDocumentService;
use App\Modules\Workforce\Signature\Services\SignatureTokenService;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\RecordSignerActionUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W-SIGNATURE-7 (ADR-659) — multi-PAdES PERSONNEL externe de bout en bout (serveur).
 *
 * Prouve : AC interne émet un cert lié à la clé publique réelle ; preuve de possession ; deux signatures
 * personnelles incrémentales (clés distinctes, jamais transmises) ; vérification INDÉPENDANTE pdfsig ;
 * A préservée après B ; cycle de vie (révocation) ; rejet des cas d'erreur ; pas de completed sans
 * signatures valides ; pas de clé privée stockée.
 */
class ExternalPadesFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');
        Notification::fake();
        config(['signature.dev_ca.enabled' => true]);
        // AC isolée par test (répertoire dédié) pour ne pas dépendre de l'état disque.
        app()->bind(\App\Core\Crypto\DevCertificateAuthority::class, fn () => new \App\Core\Crypto\DevCertificateAuthority(storage_path('app/private/dev-ca-test-' . getmypid())));

        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_documents', 'workforce_signature']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'Leezr Logistics', 'slug' => 'leezr-logistics', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_signature'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->actorId = User::factory()->create()->id;
    }

    protected function tearDown(): void
    {
        $dir = storage_path('app/private/dev-ca-test-' . getmypid());
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*'));
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function pdfsig(): NativePadesSignatureService
    {
        return app(NativePadesSignatureService::class);
    }

    // ── simulation navigateur (clé privée ne quitte jamais "l'appareil") ──

    private function ecKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
        $der = base64_decode(preg_replace('/\s+/', '', preg_replace('/-----[^-]+-----/', '', $pem)), true);

        return ['key' => $key, 'spki_b64' => base64_encode($der)];
    }

    private function browserSign(string $bytes, $key): string
    {
        openssl_sign($bytes, $der, $key, OPENSSL_ALGO_SHA256);
        $off = 2;
        $rLen = ord($der[$off + 1]);
        $r = substr($der, $off + 2, $rLen);
        $sStart = $off + 2 + $rLen;
        $sLen = ord($der[$sStart + 1]);
        $s = substr($der, $sStart + 2, $sLen);

        return base64_encode(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT));
    }

    private function makeExternalRequest(): SignatureRequest
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'code' => 'tpl-' . uniqid(), 'name' => 'Contrat de travail',
            'subject_type' => 'employee', 'body_html' => '<p>{{ company.name }}</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $path = 'documents/generated/' . $this->company->id . '/2026/10/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat</h1><p>' . uniqid() . '</p>')->output());
        $doc = GeneratedDocument::create([
            'company_id' => $this->company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'employee', 'subject_id' => 4242, 'generated_by' => $this->actorId, 'generated_at' => now(),
            'file_path' => $path, 'file_size_bytes' => 10, 'generation_snapshot' => ['x' => 1], 'signature_status' => GeneratedDocument::SIGNATURE_NONE,
        ]);

        $req = app(CreateSignatureRequestUseCase::class)->execute(
            $this->company->id, $this->actorId, $doc->id,
            [
                ['role' => SignatureSigner::ROLE_EMPLOYEE, 'name' => 'Alex Martin', 'email' => 'alex@logistics.test', 'order' => 1],
                ['role' => SignatureSigner::ROLE_COMPANY_REPRESENTATIVE, 'name' => 'Rep Leezr', 'email' => 'rep@logistics.test', 'order' => 2],
            ],
            null, SignatureRequest::ORDER_PARALLEL, null,
        );
        $req = app(SendSignatureRequestUseCase::class)->execute($this->company->id, $this->actorId, $req->id);
        $req->metadata = array_merge($req->metadata ?? [], ['required_format' => 'pades_external']);
        $req->save();

        return $req->fresh('signers');
    }

    private function issueToken(SignatureSigner $signer, SignatureRequest $req): string
    {
        $t = SignatureTokenService::generate();
        $signer->token_hash = $t['hash'];
        $signer->token_expires_at = $req->expires_at;
        $signer->token_revoked_at = null;
        $signer->status = SignatureSigner::STATUS_INVITED;
        $signer->invited_at = now();
        $signer->save();

        return $t['raw'];
    }

    /** Pilote le parcours d'un signataire (phase 1 preuve+prepare, phase 2 signature PAdES). */
    private function signAsBrowser(SignatureRequest $req, SignatureSigner $signer, array $kp): void
    {
        $token = $this->issueToken($signer, $req);

        // show() émettrait le challenge ; on le pose directement ici.
        $challenge = bin2hex(random_bytes(24));
        $signer->challenge = $challenge;
        $signer->challenge_expires_at = now()->addMinutes(15);
        $signer->challenge_used_at = null;
        $signer->save();

        $docSha = app(SignatureDocumentService::class)->fingerprint(
            GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id)
        );
        $payload = app(PersonalSignatureService::class)->canonicalPayload([
            'company_id' => (int) $req->company_id, 'request_id' => (int) $req->id, 'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email, 'document_sha256' => $docSha, 'challenge' => $challenge,
        ]);
        $pop = $this->browserSign($payload, $kp['key']);

        $uc = app(RecordSignerActionUseCase::class);
        $prep = $uc->prepareExternal($token, ['publicKey' => $kp['spki_b64'], 'pop' => $pop]);
        $padesSig = $this->browserSign(base64_decode($prep['to_sign_b64']), $kp['key']);
        $uc->sign($token, ['padesSignature' => $padesSig]);
    }

    public function test_two_browsers_two_valid_personal_signatures(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signers = $req->signers->sortBy('order')->values();
        $kpA = $this->ecKeyPair();
        $kpB = $this->ecKeyPair();

        $this->signAsBrowser($req, $signers[0], $kpA);
        $req->refresh();
        // Après le 1er signataire : artefact à 1 signature valide, demande partiellement signée.
        $this->assertSame(SignatureRequest::STATUS_PARTIALLY_SIGNED, $req->status);
        $rev1 = Storage::disk('local')->get($req->signed_document_path);
        $this->assertSame(1, (int) $this->pdfsig()->verifyPdf($rev1)['signature_count']);

        $this->signAsBrowser($req->fresh('signers'), $signers[1], $kpB);
        $req->refresh();

        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);
        $final = Storage::disk('local')->get($req->signed_document_path);
        $report = $this->pdfsig()->verifyPdf($final);
        $this->assertSame(2, (int) $report['signature_count'], 'deux signatures embarquées');
        $this->assertTrue($report['all_signatures_valid'], 'les DEUX valides (A préservée après B)');
        $cns = array_map(fn ($s) => $s['signer_cn'] ?? '', $report['signatures']);
        $this->assertContains('Alex Martin', $cns);
        $this->assertContains('Rep Leezr', $cns);

        // Deux certificats individuels émis, chacun lié à la clé publique réelle.
        $this->assertSame(2, SignatureCertificate::where('signature_request_id', $req->id)->count());
        $kidA = app(PersonalSignatureService::class)->keyId($kpA['spki_b64']);
        $this->assertTrue(SignatureCertificate::where('public_key_id', $kidA)->where('status', 'issued')->exists());

        // Aucune clé PRIVÉE stockée : seule la clé publique SPKI est persistée côté signataire.
        $s0 = $signers[0]->fresh();
        $this->assertNotEmpty($s0->public_key_spki);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $s0->signer_certificate_pem);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($s0->getAttributes()));
    }

    public function test_tampered_final_detected_by_pdfsig(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signers = $req->signers->sortBy('order')->values();
        $this->signAsBrowser($req, $signers[0], $this->ecKeyPair());
        $this->signAsBrowser($req->fresh('signers'), $signers[1], $this->ecKeyPair());
        $req->refresh();

        $bytes = Storage::disk('local')->get($req->signed_document_path);
        $bytes[300] = $bytes[300] === 'A' ? 'B' : 'A';
        $this->assertFalse($this->pdfsig()->verifyPdf($bytes)['all_signatures_valid'], 'altération détectée');
    }

    public function test_replayed_challenge_rejected(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0];
        $kp = $this->ecKeyPair();
        $token = $this->issueToken($signer, $req);

        $challenge = bin2hex(random_bytes(24));
        $signer->challenge = $challenge;
        $signer->challenge_expires_at = now()->addMinutes(15);
        $signer->save();
        $docSha = app(SignatureDocumentService::class)->fingerprint(GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id));
        $payload = app(PersonalSignatureService::class)->canonicalPayload([
            'company_id' => (int) $req->company_id, 'request_id' => (int) $req->id, 'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email, 'document_sha256' => $docSha, 'challenge' => $challenge,
        ]);
        $pop = $this->browserSign($payload, $kp['key']);
        $uc = app(RecordSignerActionUseCase::class);
        $uc->prepareExternal($token, ['publicKey' => $kp['spki_b64'], 'pop' => $pop]); // consomme le challenge

        // Rejeu de la même preuve → challenge déjà consommé.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $uc->prepareExternal($token, ['publicKey' => $kp['spki_b64'], 'pop' => $pop]);
    }

    public function test_empty_pades_body_rejected_without_burning_token(): void
    {
        // H1 — une demande pades_external EXIGE une signature PAdES ; un POST « vide » ne doit NI marquer
        // signé, NI brûler le token, NI émettre un faux événement.
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0];
        $token = $this->issueToken($signer, $req);

        try {
            app(RecordSignerActionUseCase::class)->sign($token, []); // aucun padesSignature
            $this->fail('Expected ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // attendu
        }
        $signer->refresh();
        $this->assertNotSame(SignatureSigner::STATUS_SIGNED, $signer->status, 'non marqué signé');
        $this->assertNull($signer->token_revoked_at, 'token préservé');
    }

    /** Rend un signataire INTERNE (rattaché à un compte Leezr) et renvoie l'id du user attendu. */
    private function makeInternal(SignatureSigner $signer): int
    {
        $u = \App\Core\Models\User::factory()->create();
        $signer->party_type = 'user';
        $signer->party_id = $u->id;
        $signer->save();

        return $u->id;
    }

    public function test_device_change_reenrollment_allowed_for_authenticated_internal_signer(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0];
        $uid = $this->makeInternal($signer);
        $token = $this->issueToken($signer, $req);
        $uc = app(RecordSignerActionUseCase::class);

        // Appareil A : enrôlement AUTHENTIFIÉ (compte Leezr du signataire).
        $this->enrollOnly($req, $signer, $token, $this->ecKeyPair(), $uid);
        $signer->refresh();
        $kidA = $signer->public_key_id;
        $this->assertSame('account_authenticated', $signer->identity_assurance);

        // Appareil B (clé perdue) : re-enrôlement AUTHENTIFIÉ → autorisé (ancien cert révoqué, nouveau émis).
        $kpB = $this->ecKeyPair();
        $sB = SignatureSigner::withoutGlobalScopes()->find($signer->id);
        $sB->challenge = bin2hex(random_bytes(24));
        $sB->challenge_expires_at = now()->addMinutes(15);
        $sB->challenge_used_at = null;
        $sB->save();
        $prep = $uc->prepareExternal($token, ['publicKey' => $kpB['spki_b64'], 'pop' => $this->popFor($req, $sB, $kpB), 'authUserId' => $uid]);
        $signer->refresh();
        $this->assertNotSame($kidA, $signer->public_key_id, 'nouvelle clé enregistrée');
        $this->assertSame(1, SignatureCertificate::where('signature_signer_id', $signer->id)->where('status', 'revoked')->count());
        $this->assertSame(1, SignatureCertificate::where('signature_signer_id', $signer->id)->where('status', 'issued')->count());

        $uc->sign($token, ['padesSignature' => $this->browserSign(base64_decode($prep['to_sign_b64']), $kpB['key'])]);
        $req->refresh();
        $this->assertSame(1, (int) $this->pdfsig()->verifyPdf(Storage::disk('local')->get($req->signed_document_path))['signature_count']);
    }

    public function test_internal_reenrollment_without_auth_blocked(): void
    {
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0];
        $uid = $this->makeInternal($signer);
        $token = $this->issueToken($signer, $req);
        $this->enrollOnly($req, $signer, $token, $this->ecKeyPair(), $uid); // clé A (authentifié)

        $sB = SignatureSigner::withoutGlobalScopes()->find($signer->id);
        $sB->challenge = bin2hex(random_bytes(24));
        $sB->challenge_expires_at = now()->addMinutes(15);
        $sB->challenge_used_at = null;
        $sB->save();
        $kpB = $this->ecKeyPair();
        // Re-enrôlement SANS authentification (simple possession du lien) → REFUSÉ pour un interne.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RecordSignerActionUseCase::class)->prepareExternal($token, ['publicKey' => $kpB['spki_b64'], 'pop' => $this->popFor($req, $sB, $kpB)]);
    }

    public function test_external_reenrollment_blocked_self_service(): void
    {
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0]; // externe (pas de party)
        $token = $this->issueToken($signer, $req);
        $this->enrollOnly($req, $signer, $token, $this->ecKeyPair()); // clé A (declared)

        $sB = SignatureSigner::withoutGlobalScopes()->find($signer->id);
        $sB->challenge = bin2hex(random_bytes(24));
        $sB->challenge_expires_at = now()->addMinutes(15);
        $sB->challenge_used_at = null;
        $sB->save();
        $kpB = $this->ecKeyPair();
        // Un tiers possédant le lien NE PEUT PAS substituer la clé d'un signataire externe en libre-service.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RecordSignerActionUseCase::class)->prepareExternal($token, ['publicKey' => $kpB['spki_b64'], 'pop' => $this->popFor($req, $sB, $kpB)]);
    }

    public function test_admin_reset_key_unblocks_then_signer_reenrolls(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0]; // externe
        $token = $this->issueToken($signer, $req);
        $this->enrollOnly($req, $signer, $token, $this->ecKeyPair()); // clé A
        $this->assertNotNull($signer->fresh()->public_key_id);

        // L'émetteur réinitialise la clé (révoque + libère l'enrôlement) — il ne signe JAMAIS.
        app(\App\Modules\Workforce\Signature\Services\SignerEnrollmentService::class)
            ->adminResetKey($req->fresh(), $signer->fresh(), $this->actorId);
        $this->assertNull($signer->fresh()->public_key_id, 'clé libérée');
        $this->assertSame(1, SignatureCertificate::where('signature_signer_id', $signer->id)->where('status', 'revoked')->count());

        // Le signataire ré-enrôle SA propre nouvelle clé (1re inscription à nouveau) et signe.
        $sB = SignatureSigner::withoutGlobalScopes()->find($signer->id);
        $sB->challenge = bin2hex(random_bytes(24));
        $sB->challenge_expires_at = now()->addMinutes(15);
        $sB->challenge_used_at = null;
        $sB->save();
        $kpB = $this->ecKeyPair();
        $uc = app(RecordSignerActionUseCase::class);
        $prep = $uc->prepareExternal($token, ['publicKey' => $kpB['spki_b64'], 'pop' => $this->popFor($req, $sB, $kpB)]);
        $uc->sign($token, ['padesSignature' => $this->browserSign(base64_decode($prep['to_sign_b64']), $kpB['key'])]);
        $req->refresh();
        $this->assertSame(1, (int) $this->pdfsig()->verifyPdf(Storage::disk('local')->get($req->signed_document_path))['signature_count']);
    }

    public function test_non_p256_curve_rejected(): void
    {
        $ca = app(\App\Core\Crypto\DevCertificateAuthority::class);
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        $spki = base64_encode(base64_decode(preg_replace('/\s+/', '', preg_replace('/-----[^-]+-----/', '', openssl_pkey_get_details($k)['key']))));
        $this->expectException(\RuntimeException::class);
        $ca->issueSignerCertificate($spki, ['cn' => 'Wrong Curve', 'c' => 'FR']);
    }

    /** Enrôle (prepare) un signataire sans signer (pour les scénarios appareil/clé). */
    private function enrollOnly(SignatureRequest $req, SignatureSigner $signer, string $token, array $kp, ?int $authUserId = null): void
    {
        $s = SignatureSigner::withoutGlobalScopes()->findOrFail($signer->id);
        $s->challenge = bin2hex(random_bytes(24));
        $s->challenge_expires_at = now()->addMinutes(15);
        $s->challenge_used_at = null;
        $s->save();
        app(RecordSignerActionUseCase::class)->prepareExternal($token, ['publicKey' => $kp['spki_b64'], 'pop' => $this->popFor($req, $s, $kp), 'authUserId' => $authUserId]);
    }

    private function popFor(SignatureRequest $req, SignatureSigner $signer, array $kp): string
    {
        $docSha = app(SignatureDocumentService::class)->fingerprint(GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id));
        $payload = app(PersonalSignatureService::class)->canonicalPayload([
            'company_id' => (int) $req->company_id, 'request_id' => (int) $req->id, 'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email, 'document_sha256' => $docSha, 'challenge' => $signer->challenge,
        ]);

        return $this->browserSign($payload, $kp['key']);
    }

    public function test_revoked_certificate_blocks_new_signature(): void
    {
        if (! $this->pdfsig()->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $req = $this->makeExternalRequest();
        $signer = $req->signers->sortBy('order')->values()[0];
        $kp = $this->ecKeyPair();
        $token = $this->issueToken($signer, $req);

        $challenge = bin2hex(random_bytes(24));
        $signer->challenge = $challenge;
        $signer->challenge_expires_at = now()->addMinutes(15);
        $signer->save();
        $docSha = app(SignatureDocumentService::class)->fingerprint(GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id));
        $payload = app(PersonalSignatureService::class)->canonicalPayload([
            'company_id' => (int) $req->company_id, 'request_id' => (int) $req->id, 'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email, 'document_sha256' => $docSha, 'challenge' => $challenge,
        ]);
        $pop = $this->browserSign($payload, $kp['key']);
        $uc = app(RecordSignerActionUseCase::class);
        $uc->prepareExternal($token, ['publicKey' => $kp['spki_b64'], 'pop' => $pop]);

        // Révocation du certificat → une nouvelle signature doit être refusée.
        $signer->refresh();
        $signer->signer_cert_revoked_at = now();
        $signer->save();

        $padesSig = $this->browserSign('whatever', $kp['key']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $uc->sign($token, ['padesSignature' => $padesSig]);
    }
}
