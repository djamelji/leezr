<?php

namespace App\Core\Documents\Payslip;

use App\Core\Documents\DocumentSubject;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-762 (DMS M5) — bulletin de paie PUBLIÉ (série par sujet/période). Classé `payroll` : l'accès
 * est gouverné par DocumentAccessPolicy (jamais exposé via `documents.view` générique). Un rectificatif
 * crée une nouvelle version ; l'ancienne est conservée (superseded_at), jamais écrasée/supprimée.
 */
class PublishedPayslip extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const SOURCE_EXTERNAL = 'external_import';
    public const SOURCE_GENERATED = 'leezr_generated';

    protected $fillable = [
        'company_id', 'document_subject_type', 'document_subject_id', 'period',
        'file_path', 'file_name', 'file_sha256', 'file_size_bytes', 'source', 'source_item_id',
        'version_no', 'superseded_at', 'created_by', 'created_at',
    ];

    protected $casts = [
        'document_subject_id' => 'integer',
        'file_size_bytes' => 'integer',
        'version_no' => 'integer',
        'superseded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function subject(): DocumentSubject
    {
        return new DocumentSubject((string) $this->document_subject_type, (int) $this->document_subject_id);
    }

    public function scopeCurrent($q)
    {
        return $q->whereNull('superseded_at');
    }
}
