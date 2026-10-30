<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Crypto\ExternalPadesSigner;
use App\Core\Crypto\NativePadesSignatureService;
use App\Core\Documents\GeneratedDocument;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-7 (ADR-659) — orchestration du multi-PAdES PERSONNEL incrémental (clé navigateur).
 *
 * Deux phases par signataire :
 *   prepare() — construit le placeholder PAdES sur la RÉVISION COURANTE (source pour le 1er signataire,
 *               PDF déjà signé pour les suivants), calcule les octets exacts `to_sign` et mémorise une
 *               préparation EXPIRANTE liée à la révision de base. Retourne `to_sign_b64` au navigateur.
 *   apply()   — reçoit la signature externe (P1363), vérifie que la préparation n'est ni expirée ni
 *               obsolète (révision de base inchangée), injecte le CMS, VÉRIFIE INDÉPENDAMMENT le PDF
 *               (pdfsig : la nouvelle signature valide ET les précédentes préservées), archive la
 *               nouvelle révision. L'injection est sérialisée par le verrou de ligne de la demande.
 *
 * §9 : si une préparation a été faite sur une révision antérieure (un autre signataire a injecté entre
 * temps), elle est REJETÉE et doit être refaite sur la révision exacte — jamais de réutilisation
 * silencieuse d'une signature calculée sur d'autres octets.
 */
class ExternalPadesCoordinator
{
    private const PREP_DIR = 'signature-prep';

    public function __construct(
        private readonly SignatureDocumentService $documents,
        private readonly SignatureEventRecorder $events,
    ) {}

    public function available(): bool
    {
        return app(NativePadesSignatureService::class)->pdfsigAvailable();
    }

    /** Octets de la révision COURANTE : dernier artefact signé s'il existe, sinon le PDF source. */
    public function currentRevisionBytes(SignatureRequest $request): string
    {
        $disk = config('documents.generated_disk', 'local');
        if ($request->signed_document_path && Storage::disk($disk)->exists($request->signed_document_path)) {
            return Storage::disk($disk)->get($request->signed_document_path);
        }
        $doc = GeneratedDocument::withoutGlobalScopes()->find($request->generated_document_id);
        $bytes = $doc ? $this->documents->sourceBytes($doc) : null;
        if ($bytes === null) {
            throw ValidationException::withMessages(['document' => 'Document source introuvable.']);
        }

        return $bytes;
    }

    /**
     * Phase 1 — prépare les octets à signer pour CE signataire sur la révision courante.
     *
     * @return array{to_sign_b64:string, prep_expires_at:string, base_sha256:string}
     */
    public function prepare(SignatureRequest $request, SignatureSigner $signer): array
    {
        if (! $signer->hasUsableCertificate(now())) {
            throw ValidationException::withMessages(['certificate' => 'Certificat signataire absent, expiré ou révoqué.']);
        }

        $base = $this->currentRevisionBytes($request);
        $baseSha = hash('sha256', $base);

        $signerSvc = app(ExternalPadesSigner::class);
        $placeholder = $signerSvc->preparePlaceholderPdf($base);
        $prepared = $signerSvc->prepareDataToSign($placeholder, $signer->signer_certificate_pem);

        // Stocke le placeholder (volumineux) hors DB, et les métadonnées de préparation en DB.
        $disk = config('documents.generated_disk', 'local');
        $placeholderPath = self::PREP_DIR . '/' . $signer->id . '-' . Str::uuid()->toString() . '.pdf';
        Storage::disk($disk)->put($placeholderPath, $placeholder);

        $ttl = (int) config('signature.external_pades.prep_ttl_seconds', 900);
        $signer->signing_prep = [
            'placeholder_path' => $placeholderPath,
            'auth_attrs_hex' => $prepared['auth_attrs_hex'],
            'byte_range' => $prepared['byte_range'],
            'contents_hex_start' => $prepared['contents_hex_start'],
            'contents_hex_len' => $prepared['contents_hex_len'],
            'placeholder_sha256' => $prepared['placeholder_sha256'],
            'signer_cert_sha256' => $prepared['signer_cert_sha256'],
        ];
        $signer->signing_base_sha256 = $baseSha;
        $signer->signing_prep_expires_at = now()->addSeconds($ttl);
        $signer->save();

        return [
            'to_sign_b64' => $prepared['to_sign_b64'],
            'prep_expires_at' => $signer->signing_prep_expires_at->toIso8601String(),
            'base_sha256' => $baseSha,
        ];
    }

    /**
     * Phase 2 — injecte la signature externe dans la révision courante et vérifie indépendamment.
     *
     * @return array{signature_count:int, all_valid:bool, revision_sha256:string, path:string}
     *
     * @throws ValidationException préparation absente/expirée/obsolète, signature invalide, vérif échouée
     */
    public function apply(SignatureRequest $request, SignatureSigner $signer, string $padesP1363Base64): array
    {
        $prep = $signer->signing_prep;
        if (! is_array($prep) || empty($prep['placeholder_path'])) {
            throw ValidationException::withMessages(['signature' => 'Aucune préparation de signature active.']);
        }
        if (! $signer->signing_prep_expires_at || $signer->signing_prep_expires_at->isPast()) {
            $this->clearPrep($signer);
            throw ValidationException::withMessages(['signature' => 'Préparation de signature expirée — veuillez recommencer.']);
        }
        if (! $signer->hasUsableCertificate(now())) {
            throw ValidationException::withMessages(['certificate' => 'Certificat signataire absent, expiré ou révoqué.']);
        }

        // §9 — la révision de base ne doit pas avoir changé depuis la préparation (anti-réutilisation).
        $base = $this->currentRevisionBytes($request);
        if (! hash_equals((string) $signer->signing_base_sha256, hash('sha256', $base))) {
            $this->clearPrep($signer);
            throw ValidationException::withMessages(['signature' => 'La révision du document a changé — préparation obsolète, veuillez recommencer.']);
        }

        $disk = config('documents.generated_disk', 'local');
        if (! Storage::disk($disk)->exists($prep['placeholder_path'])) {
            $this->clearPrep($signer);
            throw ValidationException::withMessages(['signature' => 'Préparation introuvable — veuillez recommencer.']);
        }
        $placeholder = Storage::disk($disk)->get($prep['placeholder_path']);

        $native = app(NativePadesSignatureService::class);
        $priorCount = 0;
        if ($request->signed_document_path && Storage::disk($disk)->exists($request->signed_document_path)) {
            $priorCount = (int) ($native->verifyPdf($base)['signature_count'] ?? 0);
        }

        // Injection (vérifie en interne la signature externe contre la clé publique du cert) + assemblage CMS.
        $signed = app(ExternalPadesSigner::class)->injectSignature(
            $placeholder,
            [
                'auth_attrs_hex' => $prep['auth_attrs_hex'],
                'byte_range' => $prep['byte_range'],
                'contents_hex_start' => $prep['contents_hex_start'],
                'contents_hex_len' => $prep['contents_hex_len'],
                'placeholder_sha256' => $prep['placeholder_sha256'],
                'signer_cert_sha256' => $prep['signer_cert_sha256'],
            ],
            $signer->signer_certificate_pem,
            $padesP1363Base64,
        );

        // VÉRIFICATION INDÉPENDANTE (pdfsig) : nouvelle signature valide ET toutes les précédentes préservées.
        $report = $native->verifyPdf($signed);
        $count = (int) ($report['signature_count'] ?? 0);
        if (empty($report['all_signatures_valid']) || $count !== $priorCount + 1) {
            throw ValidationException::withMessages(['signature' => 'La signature PAdES produite est invalide (vérification indépendante échouée).']);
        }

        // Archive la nouvelle révision et met à jour la demande (le document est assemblé incrémentalement).
        $archived = $this->documents->archiveSignedArtifact($request, $signed);
        $revisions = $request->metadata['revisions'] ?? [];
        $revisions[] = [
            'order' => $signer->order,
            'signer_id' => $signer->id,
            'signer_cn' => $signer->name,
            'cert_fingerprint' => $signer->signer_cert_fingerprint,
            'sha256' => $archived['sha256'],
            'path' => $archived['path'],
            'signature_count' => $count,
            'signed_at' => now()->toIso8601String(),
        ];
        $request->signed_document_path = $archived['path'];
        $request->signed_document_sha256 = $archived['sha256'];
        $request->metadata = array_merge($request->metadata ?? [], [
            'signature_format' => 'pades_external',
            'revisions' => $revisions,
        ]);
        $request->save();

        $this->clearPrep($signer, $prep['placeholder_path']);

        $this->events->record($request, SignatureEvent::TYPE_PADES_EMBEDDED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SIGNER,
            'actorId' => $signer->id,
            'documentSha256' => $archived['sha256'],
            'verification' => 'pdfsig_valid',
            'payload' => [
                'signature_count' => $count,
                'cert_fingerprint' => $signer->signer_cert_fingerprint,
                'cert_serial' => $signer->signer_cert_serial,
                'format' => 'pades_external',
                'signer_controlled_key' => true,
                'private_key_transmitted' => false,
            ],
        ]);

        return [
            'signature_count' => $count,
            'all_valid' => true,
            'revision_sha256' => $archived['sha256'],
            'path' => $archived['path'],
        ];
    }

    private function clearPrep(SignatureSigner $signer, ?string $placeholderPath = null): void
    {
        $path = $placeholderPath ?? ($signer->signing_prep['placeholder_path'] ?? null);
        if ($path) {
            Storage::disk(config('documents.generated_disk', 'local'))->delete($path);
        }
        $signer->signing_prep = null;
        $signer->signing_base_sha256 = null;
        $signer->signing_prep_expires_at = null;
        $signer->save();
    }
}
