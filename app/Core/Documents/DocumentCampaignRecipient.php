<?php

namespace App\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-761 (DMS M4) — destinataire FIGÉ d'une campagne (snapshot). Porte le SUJET documentaire
 * (user OU employee sans compte) et le lien vers la demande individuelle créée.
 */
class DocumentCampaignRecipient extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_CREATED = 'created';
    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'document_campaign_id', 'document_subject_type', 'document_subject_id',
        'document_request_id', 'state', 'error',
    ];

    protected $casts = [
        'document_subject_id' => 'integer',
        'document_request_id' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DocumentCampaign::class, 'document_campaign_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }

    public function subject(): DocumentSubject
    {
        return new DocumentSubject((string) $this->document_subject_type, (int) $this->document_subject_id);
    }
}
