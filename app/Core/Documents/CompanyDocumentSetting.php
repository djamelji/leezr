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
        'reminder_policy',
        'ai_features',
    ];

    protected $casts = [
        'auto_renew_enabled' => 'boolean',
        'renew_days_before' => 'integer',
        'auto_remind_enabled' => 'boolean',
        'remind_after_days' => 'integer',
        'reminder_policy' => 'array',
        'ai_features' => 'array',
    ];

    /**
     * ADR-760 (M3) — politique de relance CONFIGURABLE (tiers en jours). Les valeurs ci-dessous
     * sont des DÉFAUTS raisonnables, surchargeables par société via `reminder_policy` — aucune
     * valeur codée en dur dans l'orchestrateur.
     */
    public const DEFAULT_REMINDER_POLICY = [
        'expiration_before_days' => [30, 7], // relances avant expiration du document (J-30, J-7)
        'expiration_at' => true,             // relance le jour de l'expiration (J0)
        'expiration_after_days' => [7],      // relance après expiration (J+7)
        'request_due_before_days' => [7],    // avant l'échéance d'une demande
        'request_due_after_days' => [1, 7],  // après l'échéance d'une demande (en retard)
    ];

    public function reminderPolicy(): array
    {
        return array_merge(self::DEFAULT_REMINDER_POLICY, $this->reminder_policy ?? []);
    }

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
