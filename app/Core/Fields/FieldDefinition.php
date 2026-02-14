<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldDefinition extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'scope',
        'label',
        'type',
        'validation_rules',
        'options',
        'is_system',
        'created_by_platform',
        'default_order',
    ];

    protected function casts(): array
    {
        return [
            'validation_rules' => 'array',
            'options' => 'array',
            'is_system' => 'boolean',
            'created_by_platform' => 'boolean',
        ];
    }

    public const SCOPE_PLATFORM_USER = 'platform_user';
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_COMPANY_USER = 'company_user';

    public const SCOPES = [
        self::SCOPE_PLATFORM_USER,
        self::SCOPE_COMPANY,
        self::SCOPE_COMPANY_USER,
    ];

    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_JSON = 'json';

    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
        self::TYPE_JSON,
    ];

    public const COMPANY_TYPES = [
        self::TYPE_STRING,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
    ];

    public const COMPANY_SCOPES = [
        self::SCOPE_COMPANY,
        self::SCOPE_COMPANY_USER,
    ];

    public const MAX_CUSTOM_FIELDS_PER_COMPANY = 20;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(FieldActivation::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(FieldValue::class);
    }
}
