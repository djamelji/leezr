<?php

namespace App\Core\Documents\Payslip;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-763 (DMS M5.1) — page source d'une liasse, rattachée à un segment (bulletin). Permet le
 * découpage multipage fiable et la revue manuelle des frontières.
 */
class PayslipImportPage extends Model
{
    public const UPDATED_AT = null;

    public const ROLE_BODY = 'body';
    public const ROLE_ANNEX = 'annex';
    public const ROLE_SUMMARY = 'summary';
    public const ROLE_UNKNOWN = 'unknown';

    protected $fillable = [
        'company_id', 'payslip_import_batch_id', 'payslip_import_item_id',
        'source_file_path', 'source_page_no', 'extracted_matricule', 'extracted_period',
        'page_index', 'page_total', 'is_blank', 'role', 'created_at',
    ];

    protected $casts = [
        'source_page_no' => 'integer',
        'page_index' => 'integer',
        'page_total' => 'integer',
        'is_blank' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayslipImportItem::class, 'payslip_import_item_id');
    }
}
