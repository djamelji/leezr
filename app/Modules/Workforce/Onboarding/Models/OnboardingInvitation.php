<?php

namespace App\Modules\Workforce\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-796 (L6) — one onboarding file of ONE person (HR record) in ONE company, reached through an opaque
 * token (stored as a hash only). `snapshot` = what THIS file asks (versioned) ; values provided by the
 * candidate are staged (OnboardingValue) and become HR data only through HR validation.
 */
class OnboardingInvitation extends Model
{
    public const DRAFT = 'draft';
    public const SENT = 'sent';
    public const IN_PROGRESS = 'in_progress';
    public const SUBMITTED = 'submitted';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const VALIDATED = 'validated';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';

    /** States in which the candidate may write. */
    public const CANDIDATE_WRITABLE = [self::SENT, self::IN_PROGRESS, self::CHANGES_REQUESTED];
    /** States in which the candidate may open the file (read-only once submitted). */
    public const CANDIDATE_READABLE = [self::SENT, self::IN_PROGRESS, self::CHANGES_REQUESTED, self::SUBMITTED];
    public const FINAL = [self::VALIDATED, self::EXPIRED, self::REVOKED];

    /** Purposes and DECLARED legal bases offered to HR — Leezr records the declaration, never concludes. */
    public const PURPOSES = ['hiring_onboarding', 'employment_administration'];
    public const LEGAL_BASES = ['contract_preparation', 'legal_obligation', 'consent', 'legitimate_interest'];

    protected $fillable = [
        'company_id', 'employee_id', 'job_role_id', 'status', 'token_hash', 'expires_at', 'revoked_at', 'candidate_email', 'snapshot',
        'snapshot_version', 'snapshot_history', 'purpose', 'legal_basis', 'notice_version', 'notice_text', 'retention_days', 'code_hash',
        'code_expires_at', 'code_attempts', 'open_attempts', 'hr_message', 'sent_at', 'started_at', 'submitted_at', 'validated_at',
        'validated_by', 'created_by', 'purged_at',
    ];

    protected $hidden = ['token_hash', 'code_hash'];

    protected $casts = [
        'snapshot' => 'array', 'snapshot_history' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
        'code_expires_at' => 'datetime', 'sent_at' => 'datetime', 'started_at' => 'datetime', 'submitted_at' => 'datetime',
        'validated_at' => 'datetime', 'purged_at' => 'datetime',
    ];

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OnboardingSession::class, 'onboarding_invitation_id');
    }

    public function isOpenForCandidate(): bool
    {
        return in_array($this->status, self::CANDIDATE_READABLE, true) && $this->revoked_at === null
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function items(string $kind): array
    {
        return array_values(array_filter($this->snapshot['items'] ?? [], fn ($i) => $i['kind'] === $kind));
    }
}
