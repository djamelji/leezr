<?php

namespace App\Core\Policies;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyPolicy extends Model
{
    use BelongsToCompany;

    protected $table = 'company_policies';

    protected $fillable = [
        'company_id',
        'domain',
        'policy_key',
        'value',
        'effective_from',
        'effective_until',
        'source',
        'metadata',
    ];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'metadata' => 'array',
    ];

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

    public function scopeForKey($query, string $policyKey)
    {
        return $query->where('policy_key', $policyKey);
    }
}
