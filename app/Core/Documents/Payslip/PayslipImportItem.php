<?php

namespace App\Core\Documents\Payslip;

use App\Core\Documents\DocumentSubject;
use App\Core\Workforce\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-762 (DMS M5) — segment (bulletin) détecté dans un lot, avec son matching, sa confiance et
 * son état. La publication n'est autorisée QUE pour un item « certain » ou affecté manuellement.
 */
class PayslipImportItem extends Model
{
    // Confiance du rapprochement
    public const C_CERTAIN = 'certain';     // identifiant déterministe unique et vérifié
    public const C_PROBABLE = 'probable';   // candidat probable → revue requise
    public const C_AMBIGUOUS = 'ambiguous'; // plusieurs candidats
    public const C_NONE = 'none';           // aucun candidat

    // État de revue / publication
    public const S_PENDING = 'pending';
    public const S_MATCHED = 'matched';     // certain, prêt à publier
    public const S_REVIEW = 'review';       // nécessite une décision humaine
    public const S_EXCLUDED = 'excluded';
    public const S_PUBLISHED = 'published';
    public const S_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'payslip_import_batch_id', 'file_path', 'source_file_path', 'file_sha256',
        'page_range', 'page_numbers', 'confirmed',
        'extracted_matricule', 'extracted_period', 'extracted_name',
        'matched_employee_id', 'match_confidence', 'match_candidates', 'state', 'review_reason',
        'published_member_document_id', 'error',
    ];

    protected $casts = [
        'match_candidates' => 'array',
        'page_numbers' => 'array',
        'confirmed' => 'boolean',
        'matched_employee_id' => 'integer',
    ];

    public function pages()
    {
        return $this->hasMany(PayslipImportPage::class, 'payslip_import_item_id')->orderBy('source_page_no');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayslipImportBatch::class, 'payslip_import_batch_id');
    }

    public function matchedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_employee_id');
    }

    /** Sujet documentaire de publication : l'Employee rapproché (jamais un rapprochement implicite). */
    public function subject(): ?DocumentSubject
    {
        return $this->matched_employee_id ? DocumentSubject::employee((int) $this->matched_employee_id) : null;
    }

    /** Publication autorisée ? Uniquement certain ou affecté manuellement (état matched). */
    public function isPublishable(): bool
    {
        return $this->state === self::S_MATCHED && $this->matched_employee_id !== null;
    }
}
