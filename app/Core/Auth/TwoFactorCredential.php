<?php

namespace App\Core\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TwoFactorCredential extends Model
{
    protected $fillable = [
        'authenticatable_type',
        'authenticatable_id',
        'secret',
        'backup_codes',
        'confirmed_at',
        'last_used_at',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'backup_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
