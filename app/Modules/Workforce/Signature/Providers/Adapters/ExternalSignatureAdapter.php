<?php

namespace App\Modules\Workforce\Signature\Providers\Adapters;

use App\Core\Documents\Services\DocumentIntegrityService;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\Contracts\SealsDocumentRemotely;
use App\Modules\Workforce\Signature\Providers\Contracts\SignatureProviderAdapter;
use App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome;
use Illuminate\Support\Facades\Http;

/**
 * W-SIGNATURE / ADR-765 (DMS M6) — adaptateur de prestataire de signature EXTERNE, branché sur le
 * contrat horizontal existant (aucun second moteur). Webhook sécurisé (HMAC-SHA256 fail-closed +
 * anti-rejeu horodaté), enveloppe/preuves/document final via l'API du prestataire configuré.
 *
 * HONNÊTETÉ (anti-"simulation présentée comme prod") : tant qu'aucun `webhook_secret` n'est configuré,
 * l'adaptateur se déclare `isDevOnly()` → le manager le BLOQUE comme prestataire actif en PROD. Il
 * n'annonce JAMAIS une conformité eIDAS avancée/qualifiée sans `certified=true` explicitement
 * configuré et des preuves démontrables. Sans credentials, le contrat est exercé en mode SANDBOX
 * (clairement marqué `sandbox` dans les métadonnées) et NON vérifié contre un environnement réel.
 */
class ExternalSignatureAdapter implements SealsDocumentRemotely, SignatureProviderAdapter
{
    public const KEY = 'external';

    public function __construct(private readonly DocumentIntegrityService $integrity)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    private function config(string $k, $default = null)
    {
        return config("signature.external.{$k}", $default);
    }

    public function isCertified(): bool
    {
        // JAMAIS vrai sans configuration explicite ET un prestataire réellement certifié.
        return (bool) $this->config('certified', false);
    }

    public function isDevOnly(): bool
    {
        // Sans secret de webhook configuré → sandbox/dev uniquement (bloqué en PROD par le manager).
        return empty($this->config('webhook_secret'));
    }

    public function supportedLevels(string $marketKey): array
    {
        $levels = array_values(array_intersect(
            (array) $this->config('levels', [SignatureRequest::LEVEL_SIMPLE]),
            SignatureRequest::LEVELS,
        ));
        // Niveaux certifiés (advanced/qualified) uniquement si le prestataire est réellement certifié.
        if (! $this->isCertified()) {
            $levels = array_values(array_intersect($levels, [SignatureRequest::LEVEL_SIMPLE]));
        }

        return $levels ?: [SignatureRequest::LEVEL_SIMPLE];
    }

    public function supportsLevel(string $marketKey, string $level): bool
    {
        return in_array($level, $this->supportedLevels($marketKey), true);
    }

    public function createEnvelope(SignatureRequest $request, array $signers): array
    {
        $apiBase = (string) $this->config('api_base', '');

        // Mode PRESTATAIRE réel (credentials présents) : crée l'enveloppe via l'API documentée.
        if ($apiBase !== '' && $this->config('api_key')) {
            $resp = Http::withToken((string) $this->config('api_key'))
                ->timeout(20)
                ->post(rtrim($apiBase, '/').'/envelopes', [
                    'external_id' => (string) $request->id,
                    'document_sha256' => $request->document_sha256,
                    'level' => $request->signature_level,
                    'ordered' => $request->signing_order === SignatureRequest::ORDER_SEQUENTIAL,
                    'signers' => array_map(fn ($s) => ['id' => $s->id, 'email' => $s->email, 'name' => $s->name, 'role' => $s->role], $signers),
                ]);
            if ($resp->failed()) {
                throw new \RuntimeException('External signature provider rejected the envelope.');
            }
            $data = $resp->json();
            if (! is_array($data) || empty($data['id'])) {
                throw new \RuntimeException('External signature provider returned no envelope reference.');
            }
            $signerRefs = [];
            foreach ($signers as $s) {
                $signerRefs[$s->id] = (string) ($data['signers'][$s->id] ?? $data['signer_references'][$s->id] ?? ('ext-signer-'.$s->id));
            }

            return ['provider_reference' => (string) $data['id'], 'signer_references' => $signerRefs, 'certified' => $this->isCertified()];
        }

        // Mode SANDBOX (aucun credential) : références déterministes, clairement marqué.
        $providerRef = 'ext-sandbox-'.$request->id.'-'.substr(hash('sha256', (string) $request->id.$request->document_sha256), 0, 16);
        $signerRefs = [];
        foreach ($signers as $s) {
            $signerRefs[$s->id] = 'ext-signer-'.$request->id.'-'.$s->id;
        }

        return ['provider_reference' => $providerRef, 'signer_references' => $signerRefs, 'certified' => false];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $secret = (string) $this->config('webhook_secret', '');
        if ($secret === '') {
            throw new \RuntimeException('External signature webhook secret is not configured.');
        }

        $timestamp = $this->header($headers, 'x-signature-timestamp');
        $provided = $this->header($headers, 'x-signature');
        if ($timestamp === null || $provided === null) {
            throw new \RuntimeException('Missing webhook signature headers.');
        }

        // Anti-rejeu : horodatage dans la fenêtre de tolérance.
        $tolerance = (int) $this->config('replay_tolerance_seconds', 300);
        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            throw new \RuntimeException('Webhook timestamp outside tolerance (replay protection).');
        }

        // HMAC-SHA256 sur "timestamp.rawBody", comparaison en temps constant (fail-closed).
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $provided)) {
            throw new \RuntimeException('Invalid webhook signature.');
        }
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookOutcome
    {
        $type = (string) ($payload['type'] ?? '');
        $action = match ($type) {
            'signer.signed', 'signature.signed' => 'signed',
            'signer.declined', 'signature.declined' => 'declined',
            'envelope.completed', 'signature.completed' => 'completed',
            'envelope.failed', 'envelope.expired', 'signature.failed' => 'failed',
            default => 'ignored',
        };
        if ($action === 'ignored') {
            return WebhookOutcome::ignored();
        }

        $signerRef = $payload['signer_reference'] ?? null;
        $requestId = null;
        if ($ref = ($payload['provider_reference'] ?? null)) {
            // Webhook context: no tenant is bound — resolve explicitly, scoped to THIS provider.
            $requestId = SignatureRequest::withoutGlobalScopes()->where('provider', self::KEY)->where('provider_reference', $ref)->value('id');
        }

        return new WebhookOutcome(
            handled: true,
            signatureRequestId: $requestId ? (int) $requestId : null,
            signerReference: $signerRef,
            action: $action,
            metadata: [
                'reason' => $payload['reason'] ?? null,
                'final_document_url' => $payload['final_document_url'] ?? null,
                'final_document_sha256' => $payload['final_document_sha256'] ?? null,
                // Raw envelope reference: lets the caller tell "not provided" from "provided but unknown".
                'provider_reference' => $payload['provider_reference'] ?? null,
            ],
        );
    }

    public function verifyEvidence(SignatureRequest $request): bool
    {
        // Preuve = intégrité du document final (hash) + présence du fichier signé. N'affirme AUCUNE
        // certification eIDAS (celle-ci dépend du prestataire réel + isCertified()).
        if (empty($request->signed_document_path) || empty($request->signed_document_sha256)) {
            return false;
        }

        return $this->integrity->verify($request->signed_document_path, $request->signed_document_sha256);
    }

    public function fetchFinalDocument(SignatureRequest $request): ?string
    {
        $url = $request->metadata['final_document_url'] ?? null;
        if (! $url || ! $this->config('api_key')) {
            return null;
        }
        // ADR-767 — the URL comes from a webhook payload: the API token is only ever sent to the
        // configured provider origin (never to an arbitrary host).
        if (! $this->isProviderUrl((string) $url)) {
            return null;
        }

        $resp = Http::withToken((string) $this->config('api_key'))->timeout(30)->get($url);
        if ($resp->failed()) {
            return null;
        }
        $bytes = $resp->body();

        // Vérifie que le document retourné est bien un PDF (signature réelle, pas une page d'erreur).
        return str_starts_with($bytes, '%PDF-') ? $bytes : null;
    }

    public function awaitsSealedDocument(SignatureRequest $request): bool
    {
        // Sandbox (no credentials): nothing will ever be delivered → do not wait.
        if ((string) $this->config('api_base', '') === '' || ! $this->config('api_key')) {
            return false;
        }

        return empty($request->metadata['final_document_url']);
    }

    /** Same scheme + host (+ port) as the configured API base. */
    private function isProviderUrl(string $url): bool
    {
        $base = parse_url((string) $this->config('api_base', ''));
        $target = parse_url($url);
        if (empty($base['host']) || empty($target['host'])) {
            return false;
        }

        return strtolower($base['scheme'] ?? '') === strtolower($target['scheme'] ?? '')
            && strtolower($base['host']) === strtolower($target['host'])
            && ($base['port'] ?? null) === ($target['port'] ?? null);
    }

    /** Lecture d'un en-tête (insensible à la casse ; HeaderBag renvoie des tableaux). */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $name) {
                return is_array($v) ? ($v[0] ?? null) : (string) $v;
            }
        }

        return null;
    }
}
