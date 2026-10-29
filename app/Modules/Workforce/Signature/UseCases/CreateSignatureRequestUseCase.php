<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureDocumentService;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\Services\SignatureLevelCatalog;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-1 (ADR-651) — crée une demande de signature (statut draft) sur un document RH ÉMIS.
 *
 * Invariants : document réellement émis (GeneratedDocument persisté, jamais un aperçu) ; une seule
 * demande ACTIVE par document ; niveau réellement supporté par (prestataire × Market) ; empreinte
 * du source figée. Ne déclenche AUCUNE invitation (voir SendSignatureRequestUseCase).
 *
 * Exigences d'authentification par niveau (sémantique, pas une étiquette) : un niveau ne peut être
 * retenu que si le prestataire sait le produire.
 */
class CreateSignatureRequestUseCase
{
    private const REQUIRED_AUTH = [
        SignatureRequest::LEVEL_SIMPLE => 'email',
        SignatureRequest::LEVEL_ADVANCED => 'id_verification',
        SignatureRequest::LEVEL_QUALIFIED => 'qualified_id',
    ];

    public function __construct(
        private readonly SignatureDocumentService $documents,
        private readonly SignatureEventRecorder $events,
        private readonly SignatureSettingsService $settings,
    ) {}

    /**
     * @param array<int,array{role:string,name:string,email:string,party_type?:?string,party_id?:?int,order?:int}> $signers
     */
    public function execute(
        int $companyId,
        int $actorId,
        int $generatedDocumentId,
        array $signers,
        ?string $level = null,
        ?string $signingOrder = null,
        ?string $provider = null,
    ): SignatureRequest {
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);

        $document = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($generatedDocumentId);

        // 1. Le document ne doit pas déjà être signé.
        if ($document->signature_status === GeneratedDocument::SIGNATURE_SIGNED) {
            throw ValidationException::withMessages(['document' => 'Document already signed.']);
        }

        // 2. Une seule demande ACTIVE (non terminale) par document.
        $activeExists = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('generated_document_id', $generatedDocumentId)
            ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)
            ->exists();
        if ($activeExists) {
            throw ValidationException::withMessages(['document' => 'An active signature request already exists for this document.']);
        }

        // 3. Au moins un signataire.
        if (count($signers) === 0) {
            throw ValidationException::withMessages(['signers' => 'At least one signer is required.']);
        }

        // 4. Résolution des réglages + prestataire + niveau réellement supporté (prestataire × Market).
        $cfg = $this->settings->resolve($companyId);
        $providerKey = $provider ?: $cfg['provider'];
        $adapter = SignatureProviderManager::adapterFor($providerKey);
        if ($adapter === null) {
            throw ValidationException::withMessages(['provider' => 'No signature provider available in this environment.']);
        }
        $providerKey = $adapter->key();

        $level = $level ?: $cfg['default_level'];
        $marketKey = $company->market_key ?? 'FR';
        if (! SignatureLevelCatalog::supports($marketKey, $providerKey, $level)) {
            throw ValidationException::withMessages(['signature_level' => "Signature level '{$level}' is not supported by provider '{$providerKey}' for market '{$marketKey}'."]);
        }

        $signingOrder = $signingOrder ?: $cfg['default_order'];
        if (! in_array($signingOrder, SignatureRequest::ORDERS, true)) {
            throw ValidationException::withMessages(['signing_order' => 'Invalid signing order.']);
        }

        // 5. Empreinte du PDF source (document réellement sur disque).
        $fingerprint = $this->documents->fingerprint($document);
        if ($fingerprint === null) {
            throw ValidationException::withMessages(['document' => 'Source document file not found; cannot start a signature request.']);
        }

        $requiredAuth = self::REQUIRED_AUTH[$level] ?? 'email';
        $validityDays = max(1, (int) $cfg['validity_days']);

        return DB::transaction(function () use ($companyId, $actorId, $document, $signers, $level, $signingOrder, $providerKey, $fingerprint, $requiredAuth, $validityDays) {
            $request = SignatureRequest::create([
                'company_id' => $companyId,
                'generated_document_id' => $document->id,
                'subject_type' => $document->subject_type,
                'subject_id' => $document->subject_id,
                'document_sha256' => $fingerprint,
                'document_version' => $document->template_version,
                'initiator_id' => $actorId,
                'provider' => $providerKey,
                'signature_level' => $level,
                'signing_order' => $signingOrder,
                'status' => SignatureRequest::STATUS_DRAFT,
                'expires_at' => now()->addDays($validityDays),
                'metadata' => ['required_auth_method' => $requiredAuth],
            ]);

            $position = 1;
            foreach ($signers as $s) {
                SignatureSigner::create([
                    'company_id' => $companyId,
                    'signature_request_id' => $request->id,
                    'order' => $s['order'] ?? $position,
                    'role' => $s['role'],
                    'party_type' => $s['party_type'] ?? null,
                    'party_id' => $s['party_id'] ?? null,
                    'name' => $s['name'],
                    'email' => $s['email'],
                    'required_auth_method' => $requiredAuth,
                    'status' => SignatureSigner::STATUS_PENDING,
                ]);
                $position++;
            }

            $this->events->record($request, \App\Modules\Workforce\Signature\Models\SignatureEvent::TYPE_CREATED, [
                'actorType' => \App\Modules\Workforce\Signature\Models\SignatureEvent::ACTOR_USER,
                'actorId' => $actorId,
                'documentSha256' => $fingerprint,
                'payload' => ['signer_count' => count($signers), 'level' => $level, 'order' => $signingOrder, 'certified' => false],
            ]);

            app(\App\Core\Audit\AuditLogger::class)->logCompany(
                $companyId,
                \App\Core\Audit\AuditAction::SIGNATURE_REQUESTED,
                'signature_request',
                (string) $request->id,
                ['actorId' => $actorId, 'actorType' => 'user', 'metadata' => ['provider' => $providerKey, 'level' => $level]],
            );

            return $request->fresh('signers');
        });
    }
}
