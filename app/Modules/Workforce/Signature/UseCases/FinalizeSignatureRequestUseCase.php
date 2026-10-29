<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureDocumentService;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\Services\SignatureNotifier;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use App\Notifications\Signature\SignedCopyNotification;

/**
 * W-SIGNATURE-1 (ADR-651) — finalisation d'une demande entièrement signée.
 *
 * GARDE-FOU CENTRAL : une demande n'est marquée « completed » (et le document « signed ») QUE si
 * (1) l'empreinte du document source est inchangée, et (2) les preuves prestataire sont vérifiées.
 * Sinon → failed (pas de faux succès). Jamais sur la seule foi d'un clic frontend.
 */
class FinalizeSignatureRequestUseCase
{
    public function __construct(
        private readonly SignatureDocumentService $documents,
        private readonly SignatureEventRecorder $events,
        private readonly SignatureNotifier $notifier,
        private readonly SignatureSettingsService $settings,
    ) {}

    public function execute(SignatureRequest $request): SignatureRequest
    {
        $document = GeneratedDocument::withoutGlobalScopes()->with('template')->findOrFail($request->generated_document_id);

        // 1. Intégrité : l'empreinte du source doit correspondre à celle figée à la demande.
        if (! $this->documents->sourceMatchesFingerprint($document, $request->document_sha256)) {
            return $this->fail($request, 'document_fingerprint_mismatch');
        }

        // 2. Preuves prestataire.
        $adapter = SignatureProviderManager::adapterFor($request->provider);
        if ($adapter === null || ! $adapter->verifyEvidence($request)) {
            return $this->fail($request, 'provider_evidence_unverified');
        }

        $this->events->record($request, SignatureEvent::TYPE_VERIFICATION_PASSED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'verification' => 'passed',
            'payload' => ['certified' => $adapter->isCertified()],
        ]);

        // 3. Document final : fourni par le prestataire, sinon copie du source (interne non certifié).
        $finalBytes = $adapter->fetchFinalDocument($request) ?? $this->documents->sourceBytes($document);
        if ($finalBytes === null) {
            return $this->fail($request, 'final_document_unavailable');
        }
        $archive = $this->documents->archiveSignedArtifact($request, $finalBytes);

        // 3bis. Cachet cryptographique RÉEL (CMS/PKCS#7 + X.509) sur le document final — best-effort :
        // un échec de cachet ne casse jamais la finalisation. Cachet SERVEUR (organisationnel),
        // NON signataire-contrôlé, NON eIDAS. Appliqué uniquement aux NOUVELLES finalisations.
        $seal = null;
        try {
            $seal = app(\App\Core\Crypto\CryptographicSealService::class)->seal($finalBytes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Signature seal failed: '.$e->getMessage());
        }

        // 4. Transition completed + artefact.
        $request->transitionTo(SignatureRequest::STATUS_COMPLETED);
        $request->completed_at = now();
        $request->signed_document_path = $archive['path'];
        $request->signed_document_sha256 = $archive['sha256'];
        if ($seal) {
            $request->metadata = array_merge($request->metadata ?? [], ['crypto_seal' => [
                'cms_b64' => $seal['cms_b64'],
                'document_sha256' => $seal['document_sha256'],
                'algorithm' => $seal['algorithm'],
                'cert_fingerprint' => $seal['cert_fingerprint'],
                'sealed_at' => $seal['sealed_at'],
                'seal_type' => 'organizational_server_seal',
                'certified' => false,
            ]]);
        }
        $request->save();

        // 5. Projection document source → signed.
        if ($document->canTransitionSignatureTo(GeneratedDocument::SIGNATURE_SIGNED)) {
            $document->transitionSignatureTo(GeneratedDocument::SIGNATURE_SIGNED);
            $document->signed_at = now();
            $document->signature_provider = $request->provider;
            $document->signature_metadata = [
                'signature_request_id' => $request->id,
                'signed_document_sha256' => $archive['sha256'],
                'certified' => $adapter->isCertified(),
                'provider' => $request->provider,
            ];
            $document->save();
        }

        if ($seal) {
            $this->events->record($request, SignatureEvent::TYPE_VERIFICATION_PASSED, [
                'actorType' => SignatureEvent::ACTOR_SYSTEM,
                'verification' => 'passed',
                'documentSha256' => $seal['document_sha256'],
                'payload' => ['cryptographic_seal' => true, 'algorithm' => $seal['algorithm'], 'cert_fingerprint' => $seal['cert_fingerprint'], 'seal_type' => 'organizational_server_seal', 'certified' => false],
            ]);
        }

        $this->events->record($request, SignatureEvent::TYPE_COMPLETED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['signed_sha256' => $archive['sha256'], 'certified' => $adapter->isCertified(), 'cryptographically_sealed' => $seal !== null],
        ]);

        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_COMPLETED,
            'signature_request', (string) $request->id,
            ['actorType' => 'system', 'metadata' => ['certified' => $adapter->isCertified()]],
        );

        $this->maybeSendSignedCopy($request);

        return $request->fresh('signers');
    }

    private function fail(SignatureRequest $request, string $reason): SignatureRequest
    {
        $this->events->record($request, SignatureEvent::TYPE_VERIFICATION_FAILED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'verification' => 'failed',
            'payload' => ['reason' => $reason],
        ]);
        if ($request->canTransitionTo(SignatureRequest::STATUS_FAILED)) {
            $request->transitionTo(SignatureRequest::STATUS_FAILED);
            $request->save();
        }
        $this->events->record($request, SignatureEvent::TYPE_FAILED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['reason' => $reason],
        ]);
        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_FAILED,
            'signature_request', (string) $request->id,
            ['actorType' => 'system', 'severity' => 'warning', 'metadata' => ['reason' => $reason]],
        );

        return $request->fresh('signers');
    }

    private function maybeSendSignedCopy(SignatureRequest $request): void
    {
        $cfg = $this->settings->resolve($request->company_id);
        if (! $cfg['send_signed_copy_to_employee'] || ! $cfg['notify_enabled']) {
            return;
        }
        $employeeSigner = $request->signers()->where('role', SignatureSigner::ROLE_EMPLOYEE)->first();
        if (! $employeeSigner || ! $employeeSigner->email) {
            return;
        }
        $company = Company::withoutGlobalScopes()->find($request->company_id);
        $this->notifier->sendSignedCopy($employeeSigner->email, new SignedCopyNotification(
            recipientName: $employeeSigner->name,
            documentLabel: $request->document?->template?->name ?? 'Document RH',
            downloadUrl: rtrim(config('app.url'), '/') . '/company/workforce/signature',
            companyName: $company?->name,
        ));
    }
}
