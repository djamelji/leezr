<?php

namespace App\Core\Workforce;

use App\Core\Markets\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConventionCollective extends Model
{
    protected $table = 'convention_collectives';

    protected $fillable = [
        'idcc',
        'short_name',
        'full_name',
        'market_key',
        'brochure_number',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ConventionCollectiveRule::class, 'convention_collective_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForMarket($query, string $marketKey)
    {
        return $query->where('market_key', $marketKey);
    }

    public function scopeByIdcc($query, string $idcc)
    {
        return $query->where('idcc', $idcc);
    }
}
