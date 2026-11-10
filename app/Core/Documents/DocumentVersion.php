<?php

namespace App\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-756 (DMS M0) — version CONSERVÉE d'un document du coffre. Immuable (pas d'updated_at).
 * Permet un remplacement NON destructif : avant d'écraser `member_documents.file_path`, la
 * version précédente (fichier + hash) est archivée ici. Les fichiers historiques déjà écrasés
 * sont irrécupérables (constaté) — on ne les invente pas.
 */
class DocumentVersion extends Model
{
    public const UPDATED_AT = null; // immuable

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_IMPORTED = 'imported';
    public const SOURCE_SIGNED = 'signed';

    protected $fillable = [
        'company_id', 'member_document_id', 'subject_type', 'subject_id', 'version_no',
        'file_path', 'file_name', 'file_size_bytes', 'mime_type', 'file_sha256',
        'source', 'uploaded_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'file_size_bytes' => 'integer',
            'subject_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'member_document_id');
    }
}
