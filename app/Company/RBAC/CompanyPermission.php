<?php

namespace App\Company\RBAC;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyPermission extends Model
{
    protected $fillable = ['key', 'label', 'module_key', 'is_admin'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CompanyRole::class, 'company_role_permission');
    }
}
