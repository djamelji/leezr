<?php

namespace App\Core\Markets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketRuleSet extends Model
{
    protected $fillable = [
        'market_key',
        'domain',
        'rule_key',
        'value',
        'effective_from',
        'effective_until',
        'source',
        'reference',
    ];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }

    public function scopeDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeActiveAt($query, $date = null)
    {
        $date ??= now();

        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }

    public function scopeForRule($query, string $ruleKey)
    {
        return $query->where('rule_key', $ruleKey);
    }
}
