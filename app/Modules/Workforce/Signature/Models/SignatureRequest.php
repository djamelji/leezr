<?php

namespace App\Modules\Workforce\Signature\Models;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * W-SIGNATURE-1 (ADR-651) — demande de signature sur un document RH émis.
 *
 * Machine à états explicite. Une demande terminée/refusée/annulée/expirée/échouée est TERMINALE :
 * elle ne peut pas redevenir brouillon ni être « ressuscitée » (nouvelle procédure = nouvelle demande).
 */
class SignatureRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_signature_requests';

    protected $fillable = [
        'company_id', 'generated_document_id', 'subject_type', 'subject_id',
        'document_sha256', 'document_version', 'initiator_id', 'provider',
        'signature_level', 'signing_order', 'status', 'provider_reference',
        'sent_at', 'completed_at', 'expires_at', 'cancelled_at', 'cancelled_reason',
        'signed_document_path', 'signed_document_sha256', 'metadata',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'document_version' => 'integer',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_PARTIALLY_SIGNED,
        self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_CANCELLED,
        self::STATUS_EXPIRED, self::STATUS_FAILED,
    ];

    // Transitions autorisées (invariants du workflow).
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PENDING, self::STATUS_CANCELLED],
        self::STATUS_PENDING => [self::STATUS_PARTIALLY_SIGNED, self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_FAILED],
        self::STATUS_PARTIALLY_SIGNED => [self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_DECLINED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_FAILED => [],
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_CANCELLED,
        self::STATUS_EXPIRED, self::STATUS_FAILED,
    ];

    // Niveaux de signature (sémantique, pas une étiquette UI : dépend des capacités réelles du prestataire).
    public const LEVEL_SIMPLE = 'simple';
    public const LEVEL_ADVANCED = 'advanced';
    public const LEVEL_QUALIFIED = 'qualified';
    public const LEVELS = [self::LEVEL_SIMPLE, self::LEVEL_ADVANCED, self::LEVEL_QUALIFIED];

    public const ORDER_SEQUENTIAL = 'sequential';
    public const ORDER_PARALLEL = 'parallel';
    public const ORDERS = [self::ORDER_SEQUENTIAL, self::ORDER_PARALLEL];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition SignatureRequest from {$this->status} to {$newStatus}");
        }
        $this->status = $newStatus;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isExpiredByTime(\DateTimeInterface $now): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan($now);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class, 'signature_request_id')->orderBy('order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class, 'signature_request_id')->orderBy('occurred_at');
    }
}
