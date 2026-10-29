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

        // 3. Octets du document final (fourni par le prestataire, sinon copie du source interne).
        $sourceFinalBytes = $adapter->fetchFinalDocument($request) ?? $this->documents->sourceBytes($document);
        if ($sourceFinalBytes === null) {
            return $this->fail($request, 'final_document_unavailable');
        }

        // 3bis. ARTEFACT CRYPTOGRAPHIQUE — P0 (ADR-655) : PAS de « completed » sans artefact produit,
        // VÉRIFIÉ et archivé. Primaire = signature PAdES EMBARQUÉE dans le PDF (vérifiée par pdfsig).
        // Repli = cachet CMS détaché (vérifié par openssl). Si AUCUN artefact vérifié → on NE complète PAS.
        $pades = app(\App\Core\Crypto\NativePadesSignatureService::class);
        $sealSvc = app(\App\Core\Crypto\CryptographicSealService::class);
        $cryptoFormat = null;
        $padesReport = null;
        $seal = null;
        $artifactBytes = $sourceFinalBytes;

        try {
            if ($pades->isAvailable() && $pades->pdfsigAvailable()) {
                $signedPdf = $pades->signPdf($sourceFinalBytes);
                $report = $pades->verifyPdf($signedPdf);
                if (! empty($report['all_signatures_valid'])) {
                    $artifactBytes = $signedPdf;
                    $cryptoFormat = 'pades_embedded';
                    $padesReport = $report;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PAdES signing failed: '.$e->getMessage());
        }

        if ($cryptoFormat === null) {
            try {
                $seal = $sealSvc->seal($artifactBytes);
                $v = $sealSvc->verify($artifactBytes, $seal['cms_b64']);
                if (! empty($v['valid'])) {
                    $cryptoFormat = 'cms_detached';
                } else {
                    $seal = null;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('CMS seal failed: '.$e->getMessage());
            }
        }

        // P0 : aucun artefact cryptographique vérifié → échec, demande NON complétée (reprise par nouvelle procédure).
        if ($cryptoFormat === null) {
            return $this->fail($request, 'crypto_artifact_unverified');
        }

        // Archive l'artefact RÉELLEMENT produit (PDF signé PAdES, ou copie source pour le cachet CMS).
        $archive = $this->documents->archiveSignedArtifact($request, $artifactBytes);

        // 4. Transition completed + artefact + format cryptographique réellement utilisé.
        $request->transitionTo(SignatureRequest::STATUS_COMPLETED);
        $request->completed_at = now();
        $request->signed_document_path = $archive['path'];
        $request->signed_document_sha256 = $archive['sha256'];
        $meta = ['signature_format' => $cryptoFormat];
        if ($cryptoFormat === 'pades_embedded') {
            $meta['pades'] = [
                'type' => $padesReport['signatures'][0]['type'] ?? null,
                'hash_algorithm' => $padesReport['signatures'][0]['hash_algorithm'] ?? null,
                'signer_cn' => $padesReport['signatures'][0]['signer_cn'] ?? null,
                'verified_by' => 'pdfsig',
                'trusted_chain' => $padesReport['any_trusted_chain'] ?? false,
                'seal_type' => 'organizational_server_seal',
                'certified' => false,
            ];
        }
        if ($seal) {
            $meta['crypto_seal'] = [
                'cms_b64' => $seal['cms_b64'],
                'document_sha256' => $seal['document_sha256'],
                'algorithm' => $seal['algorithm'],
                'cert_fingerprint' => $seal['cert_fingerprint'],
                'sealed_at' => $seal['sealed_at'],
                'seal_type' => 'organizational_server_seal',
                'certified' => false,
            ];
        }
        $request->metadata = array_merge($request->metadata ?? [], $meta);
        $request->save();

        // 5. Projection document source → signed.
        if ($document->canTransitionSignatureTo(GeneratedDocument::SIGNATURE_SIGNED)) {
            $document->transitionSignatureTo(GeneratedDocument::SIGNATURE_SIGNED);
            $document->signed_at = now();
            $document->signature_provider = $request->provider;
            $document->signature_metadata = [
                'signature_request_id' => $request->id,
                'signed_document_sha256' => $archive['sha256'],
                'signature_format' => $cryptoFormat,
                'certified' => false,
                'provider' => $request->provider,
            ];
            $document->save();
        }

        $this->events->record($request, SignatureEvent::TYPE_VERIFICATION_PASSED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'verification' => 'passed',
            'documentSha256' => $archive['sha256'],
            'payload' => ['signature_format' => $cryptoFormat, 'verified_by' => $cryptoFormat === 'pades_embedded' ? 'pdfsig' : 'openssl', 'seal_type' => 'organizational_server_seal', 'certified' => false],
        ]);

        $this->events->record($request, SignatureEvent::TYPE_COMPLETED, [
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['signed_sha256' => $archive['sha256'], 'signature_format' => $cryptoFormat, 'certified' => false],
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
