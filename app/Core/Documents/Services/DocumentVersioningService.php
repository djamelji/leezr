<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;

/**
 * ADR-756 (DMS M0) — remplacement NON destructif + protection anti-doublon.
 *
 * Avant d'écraser le fichier courant d'un document, on ARCHIVE la version précédente dans
 * `document_versions` (le fichier physique précédent n'est PAS supprimé — il reste pointé par
 * la version). Idempotence : un ré-upload d'un fichier IDENTIQUE (même SHA-256) est détecté et
 * n'ouvre pas une version inutile.
 */
class DocumentVersioningService
{
    /** Vrai si le nouveau contenu est identique au fichier courant (remplacement inutile). */
    public function isDuplicate(MemberDocument $doc, ?string $newSha256): bool
    {
        return $newSha256 !== null && $doc->file_sha256 !== null && hash_equals((string) $doc->file_sha256, $newSha256);
    }

    /**
     * Archive le fichier COURANT de `$doc` comme version conservée (avant remplacement).
     * No-op au tout premier upload (aucun fichier à conserver).
     */
    public function snapshotCurrent(MemberDocument $doc, string $source = DocumentVersion::SOURCE_UPLOAD, ?int $actorId = null): ?DocumentVersion
    {
        if (empty($doc->file_path)) {
            return null;
        }

        $subject = DocumentSubject::fromMemberDocument($doc);
        $nextNo = (int) DocumentVersion::where('member_document_id', $doc->id)->max('version_no') + 1;

        return DocumentVersion::create([
            'company_id' => $doc->company_id,
            'member_document_id' => $doc->id,
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'version_no' => $nextNo,
            'file_path' => $doc->file_path,
            'file_name' => $doc->file_name,
            'file_size_bytes' => $doc->file_size_bytes,
            'mime_type' => $doc->mime_type,
            'file_sha256' => $doc->file_sha256,
            'source' => $source,
            'uploaded_by' => $doc->uploaded_by ?? $actorId,
            'metadata' => ['archived_from' => 'replacement'],
            'created_at' => $doc->updated_at ?? now(),
        ]);
    }

    /** Historique des versions conservées (plus récente d'abord). */
    public function history(MemberDocument $doc)
    {
        return DocumentVersion::where('member_document_id', $doc->id)->orderByDesc('version_no')->get();
    }
}
