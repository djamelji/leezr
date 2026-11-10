<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-756 (DMS M0) / ADR-757 (DMS M1) — remplacement NON destructif + protection anti-doublon,
 * pour TOUT document versionnable (member, company, entité module).
 *
 * Avant d'écraser le fichier courant d'un document, on ARCHIVE la version précédente dans
 * `document_versions` (polymorphe). Le fichier physique précédent n'est PAS supprimé — il reste
 * pointé par la version. Idempotence : un ré-upload d'un fichier IDENTIQUE (même SHA-256) est
 * détecté et n'ouvre pas une version inutile.
 */
class DocumentVersioningService
{
    /** Vrai si le nouveau contenu est identique au fichier courant (remplacement inutile). */
    public function isDuplicate(Model $doc, ?string $newSha256): bool
    {
        return $newSha256 !== null
            && $doc->file_sha256 !== null
            && hash_equals((string) $doc->file_sha256, $newSha256);
    }

    /**
     * Archive le fichier COURANT de `$doc` comme version conservée (avant remplacement).
     * No-op au tout premier upload (aucun fichier à conserver).
     */
    public function snapshotCurrent(Model $doc, string $source = DocumentVersion::SOURCE_UPLOAD, ?int $actorId = null): ?DocumentVersion
    {
        if (empty($doc->file_path)) {
            return null;
        }

        $subject = $this->subjectFor($doc);
        $nextNo = (int) DocumentVersion::query()
            ->where('documentable_type', $doc->getMorphClass())
            ->where('documentable_id', $doc->getKey())
            ->max('version_no') + 1;

        return DocumentVersion::create([
            'company_id' => $doc->company_id,
            'documentable_type' => $doc->getMorphClass(),
            'documentable_id' => $doc->getKey(),
            'member_document_id' => $doc instanceof MemberDocument ? $doc->getKey() : null,
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
    public function history(Model $doc)
    {
        return DocumentVersion::query()
            ->where('documentable_type', $doc->getMorphClass())
            ->where('documentable_id', $doc->getKey())
            ->orderByDesc('version_no')
            ->get();
    }

    /** Sujet documentaire du document courant (pour archiver la version avec le bon rattachement). */
    private function subjectFor(Model $doc): DocumentSubject
    {
        if ($doc instanceof MemberDocument) {
            return DocumentSubject::fromMemberDocument($doc);
        }
        if ($doc instanceof CompanyDocument) {
            return DocumentSubject::company((int) $doc->company_id);
        }

        return DocumentSubject::forModuleEntity($doc->getMorphClass(), (int) $doc->getKey());
    }
}
