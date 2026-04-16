<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModuleActivationReason extends Model
{
    use BelongsToCompany;

    public const REASON_DIRECT = 'direct';
    public const REASON_PLAN = 'plan';
    public const REASON_BUNDLE = 'bundle';
    public const REASON_REQUIRED = 'required';

    public const VALID_REASONS = [
        self::REASON_DIRECT,
        self::REASON_PLAN,
        self::REASON_BUNDLE,
        self::REASON_REQUIRED,
    ];

    protected $fillable = [
        'company_id',
        'module_key',
        'reason',
        'source_module_key',
    ];
}
