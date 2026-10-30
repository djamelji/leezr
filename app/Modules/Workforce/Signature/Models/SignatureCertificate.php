<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-SIGNATURE-7 (ADR-659) — certificat X.509 émis par l'AC interne DEV pour un signataire.
 *
 * Trace d'émission/validité/révocation (cycle de vie §6). Métier append-only : une révocation change
 * le `status`, elle n'EFFACE jamais la ligne (nécessaire pour vérifier d'anciens documents).
 *
 * MODÈLE DE CONFIANCE : `identity_verification = declared` → l'identité est DÉCLARÉE, pas vérifiée au
 * sens réglementaire. L'AC n'est ni reconnue ni qualifiée.
 */
class SignatureCertificate extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_certificates';

    protected $fillable = [
        'company_id', 'signature_request_id', 'signature_signer_id',
        'serial', 'fingerprint_sha256', 'public_key_id', 'subject_cn', 'issuer_dn',
        'not_before', 'not_after', 'status', 'revoked_at', 'revoked_reason',
        'auth_method', 'identity_verification', 'certificate_pem', 'metadata',
    ];

    protected $casts = [
        'not_before' => 'datetime',
        'not_after' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public function isUsable(\DateTimeInterface $now): bool
    {
        if ($this->status !== self::STATUS_ISSUED || $this->revoked_at !== null) {
            return false;
        }

        return $this->not_before->lessThanOrEqualTo($now) && $this->not_after->greaterThanOrEqualTo($now);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
