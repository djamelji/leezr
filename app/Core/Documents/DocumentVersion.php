<?php

namespace App\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ADR-756 (DMS M0) / ADR-757 (DMS M1) — version CONSERVÉE d'un document du coffre. Immuable
 * (pas d'updated_at). Permet un remplacement NON destructif : avant d'écraser le fichier courant,
 * la version précédente (fichier + hash) est archivée ici. POLYMORPHE (`documentable`) → couvre
 * member ET company (et futures entités module). Les fichiers historiques déjà écrasés avant M0
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
        'company_id', 'documentable_type', 'documentable_id', 'member_document_id',
        'subject_type', 'subject_id', 'version_no',
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

    /** Owner polymorphe (MemberDocument | CompanyDocument | entité module). */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Rétro-compat M0 : accès direct au MemberDocument propriétaire. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'member_document_id');
    }
}
