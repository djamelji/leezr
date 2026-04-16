<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'module_key',
        'is_enabled_for_company',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled_for_company' => 'boolean',
            'config_json' => 'array',
        ];
    }
}
