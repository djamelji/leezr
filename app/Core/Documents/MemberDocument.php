<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDocument extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'document_type_id',
        'file_path',
        'file_name',
        'file_size_bytes',
        'mime_type',
        'uploaded_by',
        'expires_at',
        'ocr_text',
        'ai_analysis',
        'ai_insights',
        'ai_suggestions',
        'ai_status',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'expires_at' => 'datetime',
        'ai_analysis' => 'array',
        'ai_insights' => 'array',
        'ai_suggestions' => 'array',
    ];

    public const AI_STATUS_PENDING = 'pending';

    public const AI_STATUS_PROCESSING = 'processing';

    public const AI_STATUS_COMPLETED = 'completed';

    public const AI_STATUS_FAILED = 'failed';

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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
