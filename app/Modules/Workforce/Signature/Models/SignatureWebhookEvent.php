<?php

namespace App\Modules\Workforce\Signature\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W-SIGNATURE-1 (ADR-651) — déduplication des webhooks prestataire (unique provider+event_id).
 * Non tenant-scopé : un webhook arrive hors contexte Company.
 */
class SignatureWebhookEvent extends Model
{
    protected $table = 'signature_webhook_events';

    protected $fillable = ['provider', 'event_id', 'event_type', 'status', 'payload'];

    protected $casts = ['payload' => 'array'];

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';
}
