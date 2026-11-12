<?php

namespace App\Core\Documents;

use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-761 (DMS M4) — campagne documentaire collective. Cible une population, fige ses destinataires
 * (snapshot) et crée une demande individuelle par destinataire via le moteur M2.
 */
class DocumentCampaign extends Model
{
    use BelongsToCompany;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_LAUNCHING = 'launching';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'document_type_id', 'created_by', 'name', 'message', 'due_date',
        'requires_validation', 'targeting', 'reminder_policy', 'status', 'recipients_total',
        'launched_at', 'closed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'requires_validation' => 'boolean',
        'targeting' => 'array',
        'reminder_policy' => 'array',
        'recipients_total' => 'integer',
        'launched_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DocumentCampaignRecipient::class);
    }
}
