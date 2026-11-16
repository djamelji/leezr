<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-176 / ADR-759 (DMS M2): Document request workflow model.
 *
 * Cycle : requested → submitted → in_review → approved | rejected ; approved → to_replace ;
 * tout état → cancelled. Rattaché au SUJET (user OU employee sans compte). La traçabilité
 * complète vit dans `document_request_events` (append-only) — aucune preuve supprimée.
 *
 * Un enregistrement courant par (company_id, document_subject_type, document_subject_id, type).
 */
class DocumentRequest extends Model
{
    use BelongsToCompany;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TO_REPLACE = 'to_replace';
    public const STATUS_CANCELLED = 'cancelled';

    /** Transitions autorisées (état courant → états cibles). */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_IN_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_IN_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_TO_REPLACE, self::STATUS_CANCELLED],
        self::STATUS_TO_REPLACE => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [self::STATUS_REQUESTED],
    ];

    protected $fillable = [
        'company_id',
        'document_subject_type',
        'document_subject_id',
        'user_id',
        'document_type_id',
        'status',
        'message',
        'due_date',
        'requires_validation',
        'approved_version_id',
        'reviewer_id',
        'review_note',
        'requested_at',
        'submitted_at',
        'reviewed_at',
        'last_reminded_at',
    ];

    protected $casts = [
        'document_subject_id' => 'integer',
        'requires_validation' => 'boolean',
        'due_date' => 'date',
        'requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    /**
     * ADR-810.1 — states that ASSERT a file was provided. A request may only hold one of them when its
     * subject holds a document of that type whose file is referenced (the same piece an approval
     * validates). Enforced on every write, including direct model writes (seeders, scripts).
     */
    public const FILE_BACKED_STATES = [self::STATUS_SUBMITTED, self::STATUS_IN_REVIEW, self::STATUS_APPROVED];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if (! $request->isDirty('status') || ! in_array($request->status, self::FILE_BACKED_STATES, true)) {
                return;
            }
            if ($request->evidenceDocument() === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ["Cannot mark the request {$request->status}: no file has been submitted."],
                ]);
            }
        });
    }

    /**
     * ADR-810.1 — the document a file-backed state rests on : the subject's document of this type with
     * a referenced file (company vault for a company subject ; legacy member rows matched by user_id).
     * Single rule shared by the transition guard and the approval.
     */
    public function evidenceDocument(): ?Model
    {
        $subjectType = $this->document_subject_type ?? ($this->user_id !== null ? DocumentSubject::TYPE_USER : null);
        $subjectId = $this->document_subject_id ?? $this->user_id;
        if ($subjectType === null || $subjectId === null) {
            return null;
        }

        if ($subjectType === DocumentSubject::TYPE_COMPANY) {
            $query = CompanyDocument::withoutGlobalScopes()->where('company_id', $this->company_id);
        } else {
            $query = MemberDocument::withoutGlobalScopes()->where('company_id', $this->company_id)
                ->where(function ($w) use ($subjectType, $subjectId) {
                    $w->where(fn ($a) => $a->where('document_subject_type', $subjectType)->where('document_subject_id', $subjectId));
                    if ($subjectType === DocumentSubject::TYPE_USER) {
                        $w->orWhere('user_id', $subjectId);
                    }
                });
        }

        return $query->where('document_type_id', $this->document_type_id)
            ->whereNotNull('file_path')->where('file_path', '!=', '')
            ->first();
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! in_array($this->status, [self::STATUS_APPROVED, self::STATUS_CANCELLED], true)
            && $this->due_date->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentRequestEvent::class)->orderBy('created_at');
    }
}
