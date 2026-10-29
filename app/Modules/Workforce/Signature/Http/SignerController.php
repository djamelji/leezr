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

        return response()->json([
            'signer' => [
                'name' => $signer->name,
                'role' => $signer->role,
                'order' => $signer->order,
                'status' => $signer->status,
                'required_auth_method' => $signer->required_auth_method,
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
            // Le token holder peut consulter le document source via la route document.
            'can_act' => $signer->tokenIsUsable(now()) && $req->isActive(),
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
        $req = app(RecordSignerActionUseCase::class)->sign($token, [
            'authMethod' => 'email_link',
            'ip' => $request->ip(),
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
