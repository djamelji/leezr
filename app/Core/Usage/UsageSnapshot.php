<?php

namespace App\Core\Usage;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageSnapshot extends Model
{
    protected $fillable = [
        'company_id',
        'date',
        'api_requests',
        'ai_requests',
        'ai_tokens',
        'emails_sent',
        'active_members',
        'storage_bytes',
    ];

    protected $casts = [
        'date' => 'date',
        'api_requests' => 'integer',
        'ai_requests' => 'integer',
        'ai_tokens' => 'integer',
        'emails_sent' => 'integer',
        'active_members' => 'integer',
        'storage_bytes' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeInPeriod($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
