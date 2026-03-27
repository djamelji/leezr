<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'scope',
        'label',
        'validation_rules',
        'requires_expiration',
        'is_system',
        'default_order',
        'archived_at',
    ];

    protected $casts = [
        'validation_rules' => 'array',
        'requires_expiration' => 'boolean',
        'is_system' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public const SCOPE_COMPANY = 'company';
    public const SCOPE_COMPANY_USER = 'company_user';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isCustom(): bool
    {
        return ! $this->is_system;
    }

    public function activations(): HasMany
    {
        return $this->hasMany(DocumentTypeActivation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function companyDocuments(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }
}
