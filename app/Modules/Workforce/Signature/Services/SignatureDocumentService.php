<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Documents\GeneratedDocument;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * W-SIGNATURE-1 (ADR-651) — intégrité documentaire et archivage.
 *
 * Empreinte SHA-256 du PDF source (au moment de la demande et re-vérifiée à la finalisation) ;
 * archivage de l'artefact signé dans un chemin SÉPARÉ, le document source restant immuable.
 */
class SignatureDocumentService
{
    private function disk(): string
    {
        return config('documents.generated_disk', 'local');
    }

    /** Octets du PDF source, ou null si le fichier est absent. */
    public function sourceBytes(GeneratedDocument $document): ?string
    {
        $disk = $this->disk();
        if (! $document->file_path || ! Storage::disk($disk)->exists($document->file_path)) {
            return null;
        }

        return Storage::disk($disk)->get($document->file_path);
    }

    /** Empreinte SHA-256 du document source émis. */
    public function fingerprint(GeneratedDocument $document): ?string
    {
        $bytes = $this->sourceBytes($document);

        return $bytes === null ? null : hash('sha256', $bytes);
    }

    /**
     * L'empreinte du source correspond-elle encore à celle enregistrée à la demande ?
     * (Détecte toute altération du document source pendant la procédure.)
     */
    public function sourceMatchesFingerprint(GeneratedDocument $document, string $expectedSha256): bool
    {
        $current = $this->fingerprint($document);

        return $current !== null && hash_equals($expectedSha256, $current);
    }

    /**
     * Archive les octets du document final signé dans un chemin séparé.
     * @return array{path:string, sha256:string, size:int}
     */
    public function archiveSignedArtifact(SignatureRequest $request, string $bytes): array
    {
        $disk = $this->disk();
        $companyId = $request->company_id;
        $year = now()->format('Y');
        $month = now()->format('m');
        $path = "documents/signed/{$companyId}/{$year}/{$month}/" . Str::uuid()->toString() . '.pdf';
        Storage::disk($disk)->put($path, $bytes);

        return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }
}
