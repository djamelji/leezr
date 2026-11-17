<?php

namespace App\Modules\Workforce\Referral\Models;

use App\Core\Traits\BelongsToCompany;
use App\Core\Workforce\JobRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-819 — one referral : a recommendation or a shared link, its candidate consent, its HR decision. */
class Referral extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_referrals';

    public const CHANNEL_RECOMMENDATION = 'recommendation';
    public const CHANNEL_LINK = 'link';

    public const LINK_SHARED = 'link_shared';           // lien partagé, personne n'a encore candidaté
    public const AWAITING_CONSENT = 'awaiting_consent'; // recommandation : le proche doit confirmer
    public const SUBMITTED = 'submitted';               // candidature reçue (consentie)
    public const IN_REVIEW = 'in_review';
    public const INFO_REQUESTED = 'info_requested';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const CONVERTED = 'converted';               // dossier d'embauche ouvert
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    /** Candidate can (still) act through the link. */
    public const CANDIDATE_OPEN = [self::LINK_SHARED, self::AWAITING_CONSENT, self::INFO_REQUESTED];

    /** HR queue (consented applications only — nothing before consent). */
    public const REVIEWABLE = [self::SUBMITTED, self::IN_REVIEW, self::INFO_REQUESTED, self::ACCEPTED];

    public const CLOSED = [self::DECLINED, self::CONVERTED, self::WITHDRAWN, self::EXPIRED];

    protected $fillable = [
        'company_id', 'referrer_user_id', 'referrer_employee_id', 'job_role_id', 'channel', 'status', 'first_name', 'last_name', 'referred_name', 'email', 'phone',
        'referrer_note', 'candidate_message', 'consent_at', 'consent_version', 'token_hash', 'link_expires_at', 'reviewed_by', 'reviewed_at',
        'review_note', 'info_request', 'onboarding_invitation_id', 'purge_after', 'anonymized_at',
    ];

    protected $casts = [
        'consent_at' => 'datetime', 'link_expires_at' => 'datetime', 'reviewed_at' => 'datetime', 'purge_after' => 'datetime', 'anonymized_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function jobRole(): BelongsTo
    {
        return $this->belongsTo(JobRole::class)->withoutGlobalScopes();
    }

    public function linkOpen(): bool
    {
        return $this->token_hash !== null && in_array($this->status, self::CANDIDATE_OPEN, true) && $this->link_expires_at?->isFuture();
    }
}
