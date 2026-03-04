<?php

namespace App\Core\Jobdomains;

use App\Core\Markets\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobdomainMarketOverlay extends Model
{
    protected $fillable = [
        'jobdomain_key',
        'market_key',
        'override_modules',
        'override_fields',
        'override_documents',
        'override_roles',
        'remove_modules',
        'remove_fields',
        'remove_documents',
        'remove_roles',
    ];

    protected function casts(): array
    {
        return [
            'override_modules' => 'array',
            'override_fields' => 'array',
            'override_documents' => 'array',
            'override_roles' => 'array',
            'remove_modules' => 'array',
            'remove_fields' => 'array',
            'remove_documents' => 'array',
            'remove_roles' => 'array',
        ];
    }

    public function jobdomain(): BelongsTo
    {
        return $this->belongsTo(Jobdomain::class, 'jobdomain_key', 'key');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }
}
