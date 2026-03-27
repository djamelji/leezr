<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDocument extends Model
{
    protected $fillable = [
        'company_id',
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
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'expires_at' => 'datetime',
        'ai_analysis' => 'array',
        'ai_insights' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
