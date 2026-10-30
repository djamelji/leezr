<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Core\Workforce\EmploymentContract;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\ReadModels\SignatureReadModel;
use App\Modules\Workforce\Signature\Services\SignatureLevelCatalog;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use App\Modules\Workforce\Signature\UseCases\CancelSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\RemindSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W-SIGNATURE-1 (ADR-651) — API Company des demandes de signature.
 */
class SignatureController extends Controller
{
    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'provider', 'search', 'per_page']);

        return response()->json(SignatureReadModel::list($request, $this->companyId($request), $filters));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);

        return response()->json(SignatureReadModel::present($req));
    }

    /** Preuves / journal d'audit — endpoint gated par workforce_signature.view_evidence. */
    public function evidence(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $req->load(['signers', 'events', 'document.template']);

        return response()->json(SignatureReadModel::present($req, withEvents: true));
    }

    public function options(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $cfg = app(SignatureSettingsService::class)->resolve($companyId);
        $company = $request->attributes->get('company') ?? \App\Core\Models\Company::withoutGlobalScopes()->find($companyId);
        $market = $company?->market_key ?? 'FR';
        $providerKey = $cfg['provider'];
        $adapter = SignatureProviderManager::adapterFor($providerKey);

        return response()->json([
            'provider' => $adapter?->key(),
            'provider_certified' => $adapter?->isCertified() ?? false,
            'provider_dev_only' => $adapter?->isDevOnly() ?? false,
            'levels' => SignatureLevelCatalog::describe($market, $providerKey),
            'orders' => SignatureRequest::ORDERS,
            'default_level' => $cfg['default_level'],
            'default_order' => $cfg['default_order'],
            // W-SIGNATURE-8 — modes de signature (vocabulaire métier) réellement disponibles.
            'formats' => $company ? \App\Modules\Workforce\Signature\Services\SignatureFormatCatalog::describe($company) : [],
            'default_format' => $cfg['default_format'],
        ]);
    }

    /** Demandes de signature liées à un collaborateur (sujet employé OU ses contrats), scopées. */
    public function byEmployee(Request $request, int $employeeId): JsonResponse
    {
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employeeId, 'employee'), 403);
        $companyId = $this->companyId($request);

        $contractIds = EmploymentContract::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->pluck('id')->all();

        $rows = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['signers', 'document.template'])
            ->where(function ($q) use ($employeeId, $contractIds) {
                $q->where(fn ($s) => $s->where('subject_type', 'employee')->where('subject_id', $employeeId));
                if ($contractIds) {
                    $q->orWhere(fn ($s) => $s->where('subject_type', 'contract')->whereIn('subject_id', $contractIds));
                }
            })
            ->orderByDesc('created_at')->get()
            ->map(fn ($r) => SignatureReadModel::present($r));

        return response()->json(['data' => $rows]);
    }

    /** Documents RH émis éligibles à une demande (scopés, non signés, sans demande active). */
    public function eligibleDocuments(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $activeDocIds = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)
            ->pluck('generated_document_id')->all();

        $docs = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('signature_status', '!=', \App\Core\Documents\GeneratedDocument::SIGNATURE_SIGNED)
            ->whereNotIn('id', $activeDocIds ?: [-1])
            ->with('template')
            ->orderByDesc('generated_at')
            ->limit(100)->get()
            ->filter(fn ($d) => $this->subjectInScope($request, $d->subject_type, (int) $d->subject_id))
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => $d->template?->name ?? 'Document',
                'subject_type' => $d->subject_type,
                'subject_id' => $d->subject_id,
                'generated_at' => $d->generated_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $docs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generated_document_id' => 'required|integer',
            'signers' => 'required|array|min:1',
            'signers.*.role' => 'required|in:' . implode(',', SignatureRequest::ORDERS) . ',company_representative,employee,external',
            'signers.*.name' => 'required|string|max:255',
            'signers.*.email' => 'required|email|max:255',
            'signers.*.order' => 'nullable|integer|min:1',
            'signers.*.party_type' => 'nullable|in:user,employee',
            'signers.*.party_id' => 'nullable|integer',
            'signature_level' => 'nullable|in:' . implode(',', SignatureRequest::LEVELS),
            'signing_order' => 'nullable|in:' . implode(',', SignatureRequest::ORDERS),
            'provider' => 'nullable|string|max:50',
            'format' => 'nullable|in:' . implode(',', \App\Modules\Workforce\Signature\Services\SignatureFormatCatalog::MODES),
        ]);

        // Vérifie l'accès au document source (scope collaborateur) AVANT création.
        $this->assertDocumentScope($request, (int) $data['generated_document_id']);

        $companyId = $this->companyId($request);
        $actorId = (int) $request->user()->id;

        $req = app(CreateSignatureRequestUseCase::class)->execute(
            companyId: $companyId,
            actorId: $actorId,
            generatedDocumentId: (int) $data['generated_document_id'],
            signers: $data['signers'],
            level: $data['signature_level'] ?? null,
            signingOrder: $data['signing_order'] ?? null,
            provider: $data['provider'] ?? null,
            format: $data['format'] ?? null,
        );

        // Confirmer = créer puis envoyer (invitations).
        $req = app(SendSignatureRequestUseCase::class)->execute($companyId, $actorId, $req->id);

        return response()->json(SignatureReadModel::present($req->load(['signers', 'document.template'])), 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->findScoped($request, $id);
        $req = app(CancelSignatureRequestUseCase::class)->execute(
            $this->companyId($request), (int) $request->user()->id, $id, $request->input('reason'),
        );

        return response()->json(SignatureReadModel::present($req->load(['signers', 'document.template'])));
    }

    public function remind(Request $request, int $id): JsonResponse
    {
        $this->findScoped($request, $id);
        $count = app(RemindSignatureRequestUseCase::class)->execute(
            $this->companyId($request), (int) $request->user()->id, $id,
        );

        return response()->json(['reminded' => $count]);
    }

    /**
     * W-SIGNATURE-9 (§3) — réinitialisation de la clé d'un signataire par l'émetteur (clé perdue, externe
     * bloqué). Révoque la clé actuelle et renvoie une invitation pour que le signataire ré-enrôle SA propre
     * nouvelle clé. L'émetteur ne signe JAMAIS à la place du signataire. Gated par workforce_signature.manage.
     */
    public function resetSignerKey(Request $request, int $id, int $signerId): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $signer = \App\Modules\Workforce\Signature\Models\SignatureSigner::withoutGlobalScopes()
            ->where('signature_request_id', $req->id)->where('id', $signerId)->firstOrFail();

        app(\App\Modules\Workforce\Signature\Services\SignerEnrollmentService::class)
            ->adminResetKey($req, $signer, (int) $request->user()->id);

        // Renvoie une invitation (nouveau lien à usage unique) si la demande est encore active.
        if ($req->isActive() && ! $signer->isActed()) {
            $token = \App\Modules\Workforce\Signature\Services\SignatureTokenService::generate();
            $signer->token_hash = $token['hash'];
            $signer->token_expires_at = $req->expires_at;
            $signer->token_revoked_at = null;
            $signer->status = \App\Modules\Workforce\Signature\Models\SignatureSigner::STATUS_INVITED;
            $signer->invited_at = now();
            $signer->save();

            $company = \App\Core\Models\Company::withoutGlobalScopes()->find($req->company_id);
            app(\App\Modules\Workforce\Signature\Services\SignatureNotifier::class)->sendInvitation(
                $signer->email,
                new \App\Notifications\Signature\SignatureInvitationNotification(
                    signerName: $signer->name,
                    documentLabel: $req->document?->template?->name ?? 'Document RH',
                    signUrl: \App\Modules\Workforce\Signature\Services\SignatureTokenService::signerUrl($token['raw']),
                    companyName: $company?->name,
                    expiresAt: $req->expires_at?->format('d/m/Y'),
                ),
            );
        }

        return response()->json(['reset' => true]);
    }

    /**
     * Vérification cryptographique INDÉPENDANTE du document final (§7). Rapport honnête : la validité
     * cryptographique du cachet N'IMPLIQUE PAS l'identité du signataire ni une reconnaissance eIDAS.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $format = $req->metadata['signature_format'] ?? ($req->metadata['crypto_seal'] ?? null ? 'cms_detached' : null);

        if (! $format || ! $req->signed_document_path) {
            return response()->json([
                'has_seal' => false,
                'message' => 'Aucun artefact cryptographique (document antérieur à la signature native ou non finalisé).',
            ]);
        }

        $disk = config('documents.generated_disk', 'local');
        if (! Storage::disk($disk)->exists($req->signed_document_path)) {
            return response()->json(['has_seal' => true, 'document_available' => false, 'message' => 'Document final introuvable.'], 404);
        }
        $bytes = Storage::disk($disk)->get($req->signed_document_path);

        // Signatures PERSONNELLES (clé du signataire) — re-vérifiées INDÉPENDAMMENT, distinctes du
        // cachet organisationnel serveur. Preuve individuelle par signataire (§5, §11).
        $personalSignatures = $this->verifyPersonalSignatures($req);

        // Bloc confiance commun (honnête, identique quel que soit le format).
        $trust = [
            'seal_type' => 'organizational_server_seal',
            'signer_identity_cryptographically_bound' => false,
            'eidas_advanced' => 'not_demonstrated',
            'eidas_qualified' => 'not_demonstrated',
            'note' => 'Signature/cachet cryptographique organisationnel (clé SERVEUR Leezr), vérifiable. L\'attribution au signataire repose sur le dossier de preuves (consentement + identification), pas sur une clé contrôlée par le signataire.',
        ];

        if ($format === 'pades_external') {
            // Multi-PAdES PERSONNEL : signatures EMBARQUÉES signées par la clé NAVIGATEUR de chaque
            // signataire (clé privée jamais transmise), certificats émis par l'AC interne DEV (NON reconnue).
            $report = app(\App\Core\Crypto\NativePadesSignatureService::class)->verifyPdf($bytes);
            if (empty($report['available'])) {
                return response()->json(['has_seal' => true, 'format' => $format, 'verifier_available' => false, 'message' => 'Vérificateur pdfsig indisponible.']);
            }

            $externalTrust = [
                'seal_type' => 'signer_controlled_personal_signatures',
                'signer_identity_cryptographically_bound' => true, // liée à la CLÉ du signataire via son cert
                'identity_verification' => 'declared',             // identité DÉCLARÉE, pas vérifiée réglementairement
                'certificate_authority' => 'leezr-dev-internal',
                'ca_recognized' => false,                          // AC DEV NON reconnue publiquement
                'recognized_trust_chain' => $report['any_trusted_chain'] ?? false,
                'eidas_advanced' => 'not_demonstrated',
                'eidas_qualified' => 'not_demonstrated',
                'note' => 'Signatures PERSONNELLES embarquées, chacune signée par la clé privée non extractible du signataire (jamais transmise à Leezr). Certificats émis par une AC interne DEV NON reconnue : l\'identité est DÉCLARÉE, pas certifiée par une autorité de confiance publique. Non eIDAS avancé/qualifié.',
            ];

            return response()->json([
                'has_seal' => true,
                'format' => 'pades_external',
                'embedded_signature' => true,
                'verified_by' => 'pdfsig',
                'signature_count' => $report['signature_count'],
                'all_signatures_valid' => $report['all_signatures_valid'],
                'document_intact' => $report['all_signatures_valid'],
                'overall_valid' => $report['all_signatures_valid'],
                'signatures' => $report['signatures'],
                'signer_certificates' => $this->signerCertificates($req),
                'personal_signatures' => $personalSignatures,
                'trust' => $externalTrust,
            ]);
        }

        if ($format === 'pades_embedded') {
            // Vérification INDÉPENDANTE par pdfsig (signature EMBARQUÉE dans le PDF).
            $report = app(\App\Core\Crypto\NativePadesSignatureService::class)->verifyPdf($bytes);
            if (empty($report['available'])) {
                return response()->json(['has_seal' => true, 'format' => $format, 'verifier_available' => false, 'message' => 'Vérificateur pdfsig indisponible.', 'trust' => $trust]);
            }

            return response()->json([
                'has_seal' => true,
                'format' => 'pades_embedded',
                'embedded_signature' => true,
                'verified_by' => 'pdfsig',
                'signature_count' => $report['signature_count'],
                'all_signatures_valid' => $report['all_signatures_valid'],
                'document_intact' => $report['all_signatures_valid'],   // signature embarquée valide ⇒ PDF non modifié
                'overall_valid' => $report['all_signatures_valid'],
                'signatures' => $report['signatures'],
                'certificate' => [
                    'identified' => true,
                    'recognized_trust_chain' => $report['any_trusted_chain'] ?? false,
                ],
                'personal_signatures' => $personalSignatures,
                'trust' => $trust,
            ]);
        }

        // CMS détaché (repli / historique récent).
        $seal = $req->metadata['crypto_seal'] ?? null;
        $svc = app(\App\Core\Crypto\CryptographicSealService::class);
        $result = $svc->verify($bytes, $seal['cms_b64'] ?? '');
        $certInfo = $svc->certificateInfo();

        return response()->json([
            'has_seal' => true,
            'format' => 'cms_detached',
            'embedded_signature' => false,
            'verified_by' => 'openssl',
            'document_intact' => $result['document_intact'],
            'signature_valid' => $result['signature_valid'],
            'overall_valid' => $result['valid'],
            'algorithm' => $seal['algorithm'] ?? null,
            'certificate' => [
                'identified' => true,
                'recognized_trust_chain' => false,
                'self_signed' => $certInfo['self_signed'] ?? true,
                'sha256_fingerprint' => $certInfo['sha256_fingerprint'] ?? null,
            ],
            'personal_signatures' => $personalSignatures,
            'trust' => $trust,
        ]);
    }

    /** Certificats individuels des signataires (AC interne DEV) — détail de preuve, honnête sur la confiance. */
    private function signerCertificates(SignatureRequest $req): array
    {
        $now = now();
        $ca = app(\App\Core\Crypto\DevCertificateAuthority::class);
        $out = [];
        foreach ($req->signers()->whereNotNull('signer_certificate_pem')->get() as $s) {
            // Validation de la CHAÎNE INTERNE (cohérence feuille→émettrice→racine) — non reconnue publiquement.
            $chainValid = null;
            try {
                $chainValid = $ca->verifyChain($s->signer_certificate_pem)['valid'] ?? null;
            } catch (\Throwable $e) {
                $chainValid = null;
            }
            $out[] = [
                'signer_name' => $s->name,
                'role' => $s->role,
                'subject_cn' => $s->name,
                'issuer' => $s->signer_cert_issuer,
                'serial' => $s->signer_cert_serial,
                'fingerprint_sha256' => $s->signer_cert_fingerprint,
                'public_key_id' => $s->public_key_id,
                'not_before' => $s->signer_cert_not_before?->toIso8601String(),
                'not_after' => $s->signer_cert_not_after?->toIso8601String(),
                'revoked' => $s->signer_cert_revoked_at !== null,
                'usable' => $s->hasUsableCertificate($now),
                'internal_chain_valid' => $chainValid,       // cohérence interne (PAS une confiance reconnue)
                // IDENTITÉ (honnête) : niveau d'assurance RÉELLEMENT obtenu, distinct de la validité crypto.
                'identity_assurance' => $s->identity_assurance ?? 'link_only',
                'account_authenticated' => $s->authenticated_user_id !== null,
                'auth_method' => $s->auth_method ?? ($s->authenticated_user_id ? 'authenticated_account' : 'token_link'),
                'reenrollment_count' => (int) ($s->reenrollment_count ?? 0),
                'identity_regulatory_verified' => false,      // jamais une vérification d'identité réglementaire
                'ca_recognized' => false,
            ];
        }

        return $out;
    }

    /** Re-vérifie INDÉPENDAMMENT les signatures personnelles stockées (preuve individuelle par signataire). */
    private function verifyPersonalSignatures(SignatureRequest $req): array
    {
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id);
        $docSha = $doc ? app(\App\Modules\Workforce\Signature\Services\SignatureDocumentService::class)->fingerprint($doc) : null;
        $svc = app(\App\Core\Crypto\PersonalSignatureService::class);
        $out = [];

        foreach ($req->signers()->whereNotNull('personal_signature')->get() as $s) {
            $valid = false;
            if ($docSha && $s->challenge && $s->public_key_spki) {
                $payload = $svc->canonicalPayload([
                    'company_id' => (int) $req->company_id,
                    'request_id' => (int) $req->id,
                    'signer_id' => (int) $s->id,
                    'signer_email' => (string) $s->email,
                    'document_sha256' => $docSha,
                    'challenge' => $s->challenge,
                ]);
                $valid = $svc->verify($s->public_key_spki, $payload, $s->personal_signature)
                    && hash_equals((string) $s->personal_signed_sha256, hash('sha256', $payload));
            }
            $out[] = [
                'signer_name' => $s->name,
                'role' => $s->role,
                'algorithm' => $s->personal_signature_alg,
                'public_key_id' => $s->public_key_id,
                'signer_controlled_key' => true,
                'verified' => $valid,
                'verified_by' => 'openssl',
            ];
        }

        return $out;
    }

    public function downloadSigned(Request $request, int $id): StreamedResponse
    {
        $req = $this->findScoped($request, $id);
        abort_unless($req->signed_document_path, 404, 'No signed document available.');
        $disk = config('documents.generated_disk', 'local');
        abort_unless(Storage::disk($disk)->exists($req->signed_document_path), 404, 'Signed document file not found.');

        return Storage::disk($disk)->download($req->signed_document_path, 'signed-' . $id . '.pdf');
    }

    public function getSettings(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $company = $request->attributes->get('company') ?? \App\Core\Models\Company::withoutGlobalScopes()->find($companyId);

        return response()->json(array_merge(
            app(SignatureSettingsService::class)->resolve($companyId),
            // Modes disponibles (+ motif d'indisponibilité) pour l'écran Paramètres RH → Signature.
            ['available_formats' => $company ? \App\Modules\Workforce\Signature\Services\SignatureFormatCatalog::describe($company) : []],
        ));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'nullable|string|max:50',
            'default_level' => 'nullable|in:' . implode(',', SignatureRequest::LEVELS),
            'default_format' => 'nullable|in:' . implode(',', \App\Modules\Workforce\Signature\Services\SignatureFormatCatalog::MODES),
            'validity_days' => 'nullable|integer|min:1|max:365',
            'reminder_days' => 'nullable|integer|min:1|max:365',
            'default_order' => 'nullable|in:' . implode(',', SignatureRequest::ORDERS),
            'notify_enabled' => 'nullable|boolean',
            'send_signed_copy_to_employee' => 'nullable|boolean',
        ]);

        return response()->json(app(SignatureSettingsService::class)->update($this->companyId($request), $data));
    }

    // ── Scope helpers ──

    private function findScoped(Request $request, int $id): SignatureRequest
    {
        $req = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->with(['signers', 'document.template'])
            ->findOrFail($id);
        $this->assertSubjectScope($request, $req->subject_type, (int) $req->subject_id);

        return $req;
    }

    private function assertDocumentScope(Request $request, int $generatedDocumentId): void
    {
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->findOrFail($generatedDocumentId);
        $this->assertSubjectScope($request, $doc->subject_type, (int) $doc->subject_id);
    }

    private function subjectInScope(Request $request, ?string $subjectType, int $subjectId): bool
    {
        if ($subjectType === 'employee') {
            return WorkforceAccess::canViewEmployeeData($request, $subjectId, 'employee');
        }
        if ($subjectType === 'contract') {
            $employeeId = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $this->companyId($request))
                ->where('id', $subjectId)->value('employee_id');

            return ! $employeeId || WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'employee');
        }

        return true;
    }

    private function assertSubjectScope(Request $request, ?string $subjectType, int $subjectId): void
    {
        if ($subjectType === 'employee') {
            abort_unless(WorkforceAccess::canViewEmployeeData($request, $subjectId, 'employee'), 403, 'Not authorized for this employee.');
        } elseif ($subjectType === 'contract') {
            $employeeId = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $this->companyId($request))
                ->where('id', $subjectId)->value('employee_id');
            if ($employeeId) {
                abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'employee'), 403, 'Not authorized for this contract.');
            }
        }
    }

}
