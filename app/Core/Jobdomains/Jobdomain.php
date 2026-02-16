<?php

namespace App\Core\Jobdomains;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Jobdomain extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'is_active',
        'default_modules',
        'default_fields',
        'default_roles',
        'allow_custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_modules' => 'array',
            'default_fields' => 'array',
            'default_roles' => 'array',
            'allow_custom_fields' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_jobdomain')
            ->withTimestamps();
    }
}
