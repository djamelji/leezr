<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-SIGNATURE-1 (ADR-651) — un signataire d'une demande.
 *
 * Le lien d'accès est un token opaque haute entropie dont on ne stocke QUE le hash (sha256).
 * `auth_method` = méthode d'authentification RÉELLEMENT employée (jamais présumée).
 */
class SignatureSigner extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_signers';

    protected $fillable = [
        'company_id', 'signature_request_id', 'order', 'role', 'party_type', 'party_id',
        'name', 'email', 'required_auth_method', 'auth_method', 'status',
        'token_hash', 'token_expires_at', 'token_revoked_at', 'invited_at', 'acted_at',
        'evidence_reference', 'decline_reason', 'metadata',
        'public_key_spki', 'public_key_id', 'personal_signature_alg', 'personal_signature',
        'personal_signed_sha256', 'challenge', 'challenge_expires_at', 'challenge_used_at',
    ];

    protected $casts = [
        'order' => 'integer',
        'party_id' => 'integer',
        'token_expires_at' => 'datetime',
        'token_revoked_at' => 'datetime',
        'invited_at' => 'datetime',
        'acted_at' => 'datetime',
        'challenge_expires_at' => 'datetime',
        'challenge_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_INVITED = 'invited';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_DECLINED = 'declined';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_INVITED, self::STATUS_SIGNED, self::STATUS_DECLINED];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_INVITED, self::STATUS_SIGNED, self::STATUS_DECLINED],
        self::STATUS_INVITED => [self::STATUS_SIGNED, self::STATUS_DECLINED],
        self::STATUS_SIGNED => [],
        self::STATUS_DECLINED => [],
    ];

    public const ROLE_COMPANY_REPRESENTATIVE = 'company_representative';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_EXTERNAL = 'external';
    public const ROLES = [self::ROLE_COMPANY_REPRESENTATIVE, self::ROLE_EMPLOYEE, self::ROLE_EXTERNAL];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition SignatureSigner from {$this->status} to {$newStatus}");
        }
        $this->status = $newStatus;
    }

    public function isActed(): bool
    {
        return in_array($this->status, [self::STATUS_SIGNED, self::STATUS_DECLINED], true);
    }

    public function tokenIsUsable(\DateTimeInterface $now): bool
    {
        if ($this->token_hash === null || $this->token_revoked_at !== null) {
            return false;
        }
        if ($this->token_expires_at !== null && $this->token_expires_at->lessThan($now)) {
            return false;
        }

        return ! $this->isActed();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
