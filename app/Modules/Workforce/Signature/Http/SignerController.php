<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Core\Documents\GeneratedDocument;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\UseCases\RecordSignerActionUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W-SIGNATURE-1 (ADR-651) — parcours PUBLIC du signataire (lien sécurisé par token opaque).
 *
 * Aucune authentification Sanctum, aucun contexte tenant : l'autorisation EST le token (haché,
 * haute entropie, expirable, révocable, à usage unique). Résolution cross-tenant via withoutGlobalScopes.
 * Réponses génériques (404) sur token invalide pour empêcher l'énumération. Le contenu documentaire
 * n'est exposé qu'au porteur d'un token VALIDE et non agi.
 */
class SignerController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $signer = $this->resolveUsable($token);
        $req = $signer->request;
        $canAct = $signer->tokenIsUsable(now()) && $req->isActive();

        // Empreinte du document EXACT présenté au signataire.
        $docSha = null;
        if ($doc = GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id)) {
            $disk = config('documents.generated_disk', 'local');
            if ($doc->file_path && Storage::disk($disk)->exists($doc->file_path)) {
                $docSha = hash('sha256', Storage::disk($disk)->get($doc->file_path));
            }
        }

        // Émet un challenge à usage unique (anti-rejeu) lié à ce signataire, si l'action est possible.
        $challenge = null;
        if ($canAct) {
            $challenge = bin2hex(random_bytes(24));
            $signer->challenge = $challenge;
            $signer->challenge_expires_at = now()->addMinutes(15);
            $signer->challenge_used_at = null;
            $signer->save();
        }

        return response()->json([
            'signer' => [
                'name' => $signer->name,
                'role' => $signer->role,
                'order' => $signer->order,
                'status' => $signer->status,
                'required_auth_method' => $signer->required_auth_method,
                'has_registered_key' => $signer->public_key_id !== null,
            ],
            'request' => [
                'status' => $req->status,
                'signing_order' => $req->signing_order,
                'signature_level' => $req->signature_level,
                'provider' => $req->provider,
                'certified' => (bool) ($req->metadata['certified'] ?? false),
                'expires_at' => $req->expires_at?->toIso8601String(),
                'document_label' => $req->document?->template?->name ?? 'Document',
            ],
            'can_act' => $canAct,
            // Données pour la signature PERSONNELLE côté navigateur (WebCrypto). Le client signe la
            // représentation canonique de ces claims avec sa clé privée NON extractible.
            'personal_signing' => $canAct ? [
                'domain' => \App\Core\Crypto\PersonalSignatureService::DOMAIN,
                'algorithm' => \App\Core\Crypto\PersonalSignatureService::ALGORITHM,
                'claims' => [
                    'company_id' => (int) $req->company_id,
                    'request_id' => (int) $req->id,
                    'signer_id' => (int) $signer->id,
                    'signer_email' => (string) $signer->email,
                    'document_sha256' => $docSha,
                    'challenge' => $challenge,
                ],
            ] : null,
        ]);
    }

    /** Document source consultable par le porteur du token valide uniquement. */
    public function document(Request $request, string $token): StreamedResponse
    {
        $signer = $this->resolveUsable($token);
        $doc = GeneratedDocument::withoutGlobalScopes()->find($signer->request->generated_document_id);
        abort_unless($doc && $doc->file_path, 404);
        $disk = config('documents.generated_disk', 'local');
        abort_unless(Storage::disk($disk)->exists($doc->file_path), 404);

        // Journalise la consultation (preuve).
        app(SignatureEventRecorder::class)->record($signer->request, SignatureEvent::TYPE_VIEWED, [
            'signer' => $signer, 'actorType' => SignatureEvent::ACTOR_SIGNER, 'actorId' => $signer->id,
        ]);

        return Storage::disk($disk)->download($doc->file_path, 'document.pdf');
    }

    public function sign(Request $request, string $token): JsonResponse
    {
        // Confirme que le token est utilisable (404 générique sinon).
        $this->resolveUsable($token);
        $data = $request->validate([
            'public_key_spki' => 'nullable|string|max:4000',
            'signature_p1363' => 'nullable|string|max:512',
        ]);

        $req = app(RecordSignerActionUseCase::class)->sign($token, [
            'ip' => $request->ip(),
            'personalPublicKey' => $data['public_key_spki'] ?? null,
            'personalSignature' => $data['signature_p1363'] ?? null,
        ]);

        return response()->json(['status' => $req->status, 'message' => 'signed']);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $this->resolveUsable($token);
        $reason = $request->input('reason');
        $req = app(RecordSignerActionUseCase::class)->decline($token, $reason, ['ip' => $request->ip()]);

        return response()->json(['status' => $req->status, 'message' => 'declined']);
    }

    /** Résout un signataire par token ; 404 générique si introuvable/inutilisable (anti-énumération). */
    private function resolveUsable(string $token): SignatureSigner
    {
        $signer = app(RecordSignerActionUseCase::class)->resolveSigner($token);
        abort_if($signer === null, 404);

        return $signer;
    }
}
