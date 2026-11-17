<?php

namespace App\Modules\Workforce\Referral\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** ADR-819 — referral settings of a company (modes, open positions, notifications, retention). */
class ReferralSettings extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_referral_settings';

    public const MODES = ['recommend', 'share', 'both'];

    protected $fillable = ['company_id', 'mode', 'job_role_ids', 'notify_reviewers', 'reward_note', 'retention_days', 'link_days', 'updated_by'];

    protected $casts = ['job_role_ids' => 'array', 'notify_reviewers' => 'boolean', 'retention_days' => 'integer', 'link_days' => 'integer'];

    public static function for(int $companyId): self
    {
        return static::withoutGlobalScopes()->firstOrNew(['company_id' => $companyId], ['mode' => 'both', 'job_role_ids' => [], 'notify_reviewers' => true, 'retention_days' => 180, 'link_days' => 30]);
    }

    public function allows(string $channel): bool
    {
        return $this->mode === 'both' || ($channel === Referral::CHANNEL_RECOMMENDATION ? $this->mode === 'recommend' : $this->mode === 'share');
    }
}
