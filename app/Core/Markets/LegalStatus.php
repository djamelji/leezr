<?php

namespace App\Core\Markets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalStatus extends Model
{
    protected $fillable = [
        'market_key',
        'key',
        'name',
        'description',
        'vat_rate',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }
}
