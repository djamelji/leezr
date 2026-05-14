<?php

namespace App\Core\Documents;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GeneratedDocument tracks a PDF document generated from a template.
 *
 * Immutable after creation — only signature fields can be updated.
 * Signed documents cannot be deleted.
 *
 * generation_snapshot contains ALL resolved variables at generation time,
 * ensuring full reproducibility and audit trail.
 */
class GeneratedDocument extends Model
{
    use BelongsToCompany;

    protected $table = 'generated_documents';

    const SUBJECT_TYPES = ['employee', 'contract', 'leave_request', 'payroll_run', 'payroll_line'];

    const SIGNATURE_NONE = 'none';
    const SIGNATURE_PENDING = 'pending';
    const SIGNATURE_SIGNED = 'signed';
    const SIGNATURE_DECLINED = 'declined';

    const SIGNATURE_TRANSITIONS = [
        self::SIGNATURE_NONE => [self::SIGNATURE_PENDING],
        self::SIGNATURE_PENDING => [self::SIGNATURE_SIGNED, self::SIGNATURE_DECLINED],
        self::SIGNATURE_SIGNED => [],
        self::SIGNATURE_DECLINED => [],
    ];

    // Fields that CAN be updated (signature only)
    const MUTABLE_FIELDS = ['signature_status', 'signature_provider', 'signature_metadata', 'signed_at'];

    protected $fillable = [
        'company_id',
        'document_template_id',
        'template_version',
        'subject_type',
        'subject_id',
        'generated_by',
        'generated_at',
        'file_path',
        'file_size_bytes',
        'generation_snapshot',
        'signature_status',
        'signature_provider',
        'signature_metadata',
        'signed_at',
        'member_document_id',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'subject_id' => 'integer',
        'generated_at' => 'datetime',
        'file_size_bytes' => 'integer',
        'generation_snapshot' => 'array',
        'signature_metadata' => 'array',
        'signed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // --- Boot: enforce immutability ---

    protected static function boot()
    {
        parent::boot();

        // I1: Only signature fields can be updated
        static::updating(function (self $doc) {
            $dirty = array_keys($doc->getDirty());
            $forbidden = array_diff($dirty, self::MUTABLE_FIELDS);

            if (! empty($forbidden)) {
                throw new \RuntimeException(
                    'Cannot update GeneratedDocument: only signature fields are mutable. Attempted: ' . implode(', ', $forbidden)
                );
            }
        });

        // I2: Signed documents cannot be deleted
        static::deleting(function (self $doc) {
            if ($doc->signature_status === self::SIGNATURE_SIGNED) {
                throw new \RuntimeException('Cannot delete a signed document.');
            }
            // I3: Official documents (non_deletable metadata) cannot be deleted
            if (($doc->metadata['non_deletable'] ?? false) === true) {
                throw new \RuntimeException('Cannot delete an official document.');
            }
        });
    }

    // --- Signature State Machine ---

    public function canTransitionSignatureTo(string $newStatus): bool
    {
        return in_array($newStatus, self::SIGNATURE_TRANSITIONS[$this->signature_status] ?? [], true);
    }

    public function transitionSignatureTo(string $newStatus): void
    {
        if (! $this->canTransitionSignatureTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition signature from '{$this->signature_status}' to '{$newStatus}'"
            );
        }
        $this->signature_status = $newStatus;
    }

    // --- Relationships ---

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'generated_by');
    }

    public function memberDocument(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'member_document_id');
    }

    // --- Helpers ---

    public function isSigned(): bool
    {
        return $this->signature_status === self::SIGNATURE_SIGNED;
    }

    public function buildIdempotencyKey(): string
    {
        return "doc:{$this->document_template_id}:{$this->subject_type}:{$this->subject_id}:{$this->template_version}";
    }
}
