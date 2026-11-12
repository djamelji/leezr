<?php

namespace App\Core\Documents\Payslip;

use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-762 (DMS M5) — lot d'import de bulletins externes.
 */
class PayslipImportBatch extends Model
{
    use BelongsToCompany;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REVIEW = 'review';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'created_by', 'period', 'status', 'items_total', 'items_published',
        'source_files', 'errors', 'processed_at',
    ];

    protected $casts = [
        'source_files' => 'array',
        'errors' => 'array',
        'items_total' => 'integer',
        'items_published' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipImportItem::class);
    }
}
