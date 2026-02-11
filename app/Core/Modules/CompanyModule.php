<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
