<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
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
    protected $fillable = [
        'company_id',
        'auto_renew_enabled',
        'renew_days_before',
        'auto_remind_enabled',
        'remind_after_days',
    ];

    protected $casts = [
        'auto_renew_enabled' => 'boolean',
        'renew_days_before' => 'integer',
        'auto_remind_enabled' => 'boolean',
        'remind_after_days' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get or create default settings for a company.
     */
    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(
            ['company_id' => $companyId],
            [
                'auto_renew_enabled' => false,
                'renew_days_before' => 30,
                'auto_remind_enabled' => false,
                'remind_after_days' => 7,
            ],
        );
    }
}
