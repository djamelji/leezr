<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypeActivation extends Model
{
    use BelongsToCompany;
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
}
