<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * W-SIGNATURE-1 (ADR-651) — paramètres Signature d'une Company (une ligne par Company).
 */
class SignatureSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_settings';

    protected $fillable = [
        'company_id', 'provider', 'default_level', 'validity_days', 'reminder_days',
        'default_order', 'notify_enabled', 'send_signed_copy_to_employee', 'metadata',
    ];

    protected $casts = [
        'validity_days' => 'integer',
        'reminder_days' => 'integer',
        'notify_enabled' => 'boolean',
        'send_signed_copy_to_employee' => 'boolean',
        'metadata' => 'array',
    ];

    public static function defaults(): array
    {
        return [
            'provider' => null,
            'default_level' => SignatureRequest::LEVEL_SIMPLE,
            'validity_days' => 30,
            'reminder_days' => null,
            'default_order' => SignatureRequest::ORDER_SEQUENTIAL,
            'notify_enabled' => true,
            'send_signed_copy_to_employee' => true,
        ];
    }
}
