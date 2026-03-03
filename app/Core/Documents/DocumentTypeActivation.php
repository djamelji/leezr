<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypeActivation extends Model
{
    protected $fillable = [
        'company_id',
        'document_type_id',
        'enabled',
        'required_override',
        'order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'required_override' => 'boolean',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
