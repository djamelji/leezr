<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-397: Company-level document automation settings.
 *
 * Controls auto-renewal requests when documents expire
 * and auto-remind for unanswered requests.
 */
class CompanyDocumentSetting extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'auto_renew_enabled',
        'renew_days_before',
        'auto_remind_enabled',
        'remind_after_days',
        'ai_features',
    ];

    protected $casts = [
        'auto_renew_enabled' => 'boolean',
        'renew_days_before' => 'integer',
        'auto_remind_enabled' => 'boolean',
        'remind_after_days' => 'integer',
        'ai_features' => 'array',
    ];

    /**
     * Get or create default settings for a company.
     */
    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(
            ['company_id' => $companyId],
            [
                'auto_renew_enabled' => true,
                'renew_days_before' => 30,
                'auto_remind_enabled' => true,
                'remind_after_days' => 7,
            ],
        );
    }

    /**
     * ADR-413: Get a specific AI feature setting with default fallback.
     */
    public function aiFeature(string $key, mixed $default = null): mixed
    {
        return data_get($this->ai_features, $key, $default);
    }
}
