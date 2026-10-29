<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-SIGNATURE-1 (ADR-651) — événement de preuve APPEND-ONLY.
 *
 * Chaque étape significative (création, envoi, invitation, consultation, signature, refus,
 * annulation, expiration, webhook, vérification) est historisée de façon immuable.
 */
class SignatureEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_events';

    protected $fillable = [
        'company_id', 'signature_request_id', 'signature_signer_id', 'type',
        'actor_type', 'actor_id', 'document_sha256', 'provider_reference',
        'verification_result', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public const TYPE_CREATED = 'created';
    public const TYPE_SENT = 'sent';
    public const TYPE_INVITED = 'invited';
    public const TYPE_VIEWED = 'viewed';
    public const TYPE_SIGNED = 'signed';
    public const TYPE_DECLINED = 'declined';
    public const TYPE_CANCELLED = 'cancelled';
    public const TYPE_EXPIRED = 'expired';
    public const TYPE_COMPLETED = 'completed';
    public const TYPE_FAILED = 'failed';
    public const TYPE_WEBHOOK_RECEIVED = 'webhook_received';
    public const TYPE_VERIFICATION_PASSED = 'verification_passed';
    public const TYPE_VERIFICATION_FAILED = 'verification_failed';

    public const ACTOR_USER = 'user';
    public const ACTOR_SIGNER = 'signer';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_PROVIDER = 'provider';

    // Append-only : interdire toute mise à jour/suppression d'un événement de preuve.
    protected static function boot(): void
    {
        parent::boot();
        static::updating(function () {
            throw new \RuntimeException('SignatureEvent is append-only and cannot be updated.');
        });
        static::deleting(function () {
            throw new \RuntimeException('SignatureEvent is append-only and cannot be deleted.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }
}
