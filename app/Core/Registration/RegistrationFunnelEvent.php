<?php

namespace App\Core\Registration;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationFunnelEvent extends Model
{
    protected $fillable = ['session_id', 'company_id', 'step', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    // ── Relations ────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ───────────────────────────────────────────

    public function scopeStep(Builder $query, string $step): Builder
    {
        return $query->where('step', $step);
    }

    public function scopeInPeriod(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
