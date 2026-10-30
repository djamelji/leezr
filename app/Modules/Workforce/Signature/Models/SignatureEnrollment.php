<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-SIGNATURE-9 (ADR-661) — historique d'enrôlement d'une clé de signataire.
 *
 * Chaque association de clé (initiale ou re-enrôlement sur nouvel appareil) est tracée avec son NIVEAU
 * D'ASSURANCE d'identité réellement obtenu. Permet l'audit des changements de clé (§3) et empêche de
 * masquer une substitution. Métier append-only (révocation = changement de statut).
 */
class SignatureEnrollment extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_enrollments';

    protected $fillable = [
        'company_id', 'signature_signer_id', 'public_key_id', 'assurance',
        'authenticated_user_id', 'reason', 'ip', 'revoked_at',
    ];

    protected $casts = [
        'authenticated_user_id' => 'integer',
        'revoked_at' => 'datetime',
    ];

    public const REASON_INITIAL = 'initial';
    public const REASON_DEVICE_CHANGE = 'device_change_reenrollment';
    public const REASON_ADMIN_RESET = 'admin_reset';

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }
}
