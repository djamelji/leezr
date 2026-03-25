<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-176: Document request workflow model.
 *
 * Tracks the lifecycle of a member document request:
 * requested → submitted → approved/rejected.
 *
 * One active request per (company_id, user_id, document_type_id).
 */
class DocumentRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'user_id',
        'document_type_id',
        'status',
        'reviewer_id',
        'review_note',
        'requested_at',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
