<?php

namespace App\Core\Jobdomains;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jobdomain extends Model
{
    /** Jobdomains offered to new companies (commercial catalogue). Historical companies are unaffected. */
    public function scopeOfferedForSignup($query)
    {
        return $query->where('is_active', true)->where('signup_available', true);
    }

    protected $fillable = [
        'key',
        'label',
        'description',
        'sector',       // ADR-797 — identité métier
        'terminology',  // ADR-797 — vocabulaire métier (ex. « collaborateur » → « livreur »)
        'convention_refs', // ADR-799 — conventions pertinentes par marché (référence, jamais un lien juridique)
        'is_active',
        'signup_available', // Logistics Only : proposé aux nouvelles inscriptions (≠ is_active)
        'default_modules',
        'recommended_modules', // ADR-820
        'experience',          // ADR-820 — { group_order, entry_points }
        'default_fields',
        'default_documents',
        'default_roles',
        'workforce_presets', // M5 (ADR-728) — propositions métier Workforce (activait la colonne morte)
        'allow_custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'signup_available' => 'boolean',
            'terminology' => 'array',
            'convention_refs' => 'array',
            'default_modules' => 'array',
            'recommended_modules' => 'array',
            'experience' => 'array',
            'default_fields' => 'array',
            'default_documents' => 'array',
            'default_roles' => 'array',
            'workforce_presets' => 'array',
            'allow_custom_fields' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_jobdomain')
            ->withTimestamps();
    }

    public function overlays(): HasMany
    {
        return $this->hasMany(JobdomainMarketOverlay::class, 'jobdomain_key', 'key');
    }
}
