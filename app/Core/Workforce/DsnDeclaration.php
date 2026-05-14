<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DsnDeclaration — persists a DSN (Déclaration Sociale Nominative) export.
 *
 * Lifecycle: draft → validated → exported → submitted → accepted|rejected
 * - draft: payload built but not yet validated
 * - validated: payload passed DsnValidator checks
 * - exported: file written to storage, ready for transmission
 * - submitted: sent to gateway (net-entreprises or stub)
 * - accepted: gateway confirmed receipt
 * - rejected: gateway rejected the declaration
 *
 * Immutable after submitted — no updates allowed once status >= submitted.
 *
 * Sprint 6.4 — ADR-522, Sprint 6.5 — ADR-523
 */
class DsnDeclaration extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_dsn_declarations';

    const STATUS_DRAFT = 'draft';
    const STATUS_VALIDATED = 'validated';
    const STATUS_EXPORTED = 'exported';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'declaration_type',
        'period_month',
        'status',
        'payload_hash',
        'file_path',
        'validation_errors',
        'payload_snapshot',
        'generated_by',
        'generated_at',
        'exported_by',
        'exported_at',
        'submission_reference',
        'submitted_by',
        'submitted_at',
        // Gateway tracking (Sprint 7.1 — ADR-529)
        'gateway_driver',
        'gateway_environment',
        'technical_receipt_path',
        'business_report_path',
        'gateway_status',
        'gateway_error_code',
        'gateway_error_message',
        'gateway_metadata',
        'last_polled_at',
        'next_poll_at',
        'attempt_count',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'payload_snapshot' => 'array',
        'generated_at' => 'datetime',
        'exported_at' => 'datetime',
        'submitted_at' => 'datetime',
        // Gateway tracking (Sprint 7.1 — ADR-529)
        'gateway_metadata' => 'array',
        'last_polled_at' => 'datetime',
        'next_poll_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    // --- Boot: enforce immutability when submitted/accepted/rejected ---

    /**
     * Fields that can be updated on submitted declarations during
     * the gateway polling lifecycle. Core payload fields remain immutable.
     *
     * Sprint 7.3 — ADR-531
     */
    private const GATEWAY_TRACKING_FIELDS = [
        'status',
        'gateway_status',
        'gateway_error_code',
        'gateway_error_message',
        'gateway_metadata',
        'technical_receipt_path',
        'business_report_path',
        'last_polled_at',
        'next_poll_at',
        'attempt_count',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $declaration) {
            $original = $declaration->getOriginal('status');
            $immutableStatuses = [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_REJECTED];

            if (! in_array($original, $immutableStatuses, true)) {
                return; // Pre-submission statuses are fully mutable
            }

            $dirty = array_keys($declaration->getDirty());

            // Submitted: allow gateway tracking fields + status→accepted/rejected
            if ($original === self::STATUS_SUBMITTED) {
                $disallowed = array_diff($dirty, self::GATEWAY_TRACKING_FIELDS);

                if (! empty($disallowed)) {
                    throw new \RuntimeException(
                        'Cannot update DsnDeclaration: fields [' . implode(', ', $disallowed)
                        . '] are immutable after submission.'
                    );
                }

                // If status is changing, validate the transition
                if (in_array('status', $dirty, true)) {
                    $newStatus = $declaration->getAttribute('status');
                    if (! in_array($newStatus, [self::STATUS_ACCEPTED, self::STATUS_REJECTED], true)) {
                        throw new \RuntimeException(
                            "Cannot transition DsnDeclaration from 'submitted' to '{$newStatus}'."
                        );
                    }
                }

                return; // All dirty fields are allowed
            }

            // Accepted/Rejected: fully immutable
            throw new \RuntimeException(
                "Cannot update DsnDeclaration: record is {$original} and immutable."
            );
        });
    }

    /**
     * Reset a rejected declaration for retry — bypasses boot immutability guard.
     *
     * Only allowed on rejected declarations. Uses DB::table to avoid
     * the Eloquent updating event, then refreshes the model.
     *
     * Sprint 7.5 — ADR-533
     */
    public function resetForRetry(): void
    {
        if ($this->status !== self::STATUS_REJECTED) {
            throw new \RuntimeException(
                "Cannot reset: declaration #{$this->id} is not rejected (status: {$this->status})."
            );
        }

        \Illuminate\Support\Facades\DB::table('workforce_dsn_declarations')
            ->where('id', $this->id)
            ->update([
                'status' => self::STATUS_EXPORTED,
                'gateway_status' => null,
                'gateway_error_code' => null,
                'gateway_error_message' => null,
                'gateway_metadata' => null,
                'submission_reference' => null,
                'technical_receipt_path' => null,
                'business_report_path' => null,
                'last_polled_at' => null,
                'next_poll_at' => null,
                'attempt_count' => 0,
                'updated_at' => now(),
            ]);

        $this->refresh();
    }

    // --- Relationships ---

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'generated_by');
    }

    public function exportedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'exported_by');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'submitted_by');
    }

    // --- State machine ---

    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            self::STATUS_DRAFT => [self::STATUS_VALIDATED],
            self::STATUS_VALIDATED => [self::STATUS_EXPORTED],
            self::STATUS_EXPORTED => [self::STATUS_SUBMITTED],
            self::STATUS_SUBMITTED => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
            self::STATUS_ACCEPTED => [],
            self::STATUS_REJECTED => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition DsnDeclaration from '{$this->status}' to '{$newStatus}'."
            );
        }

        $this->status = $newStatus;
    }

    // --- Helpers ---

    public function hasValidationErrors(): bool
    {
        return ! empty($this->validation_errors);
    }

    public function isExported(): bool
    {
        return $this->status === self::STATUS_EXPORTED;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_REJECTED], true);
    }

    /**
     * Can this declaration be regenerated (replaced by a new export)?
     *
     * Allowed: draft, validated, exported, rejected
     * Blocked: submitted, accepted (immutable after submission)
     *
     * Sprint 6.6 — ADR-524
     */
    public function canRegenerate(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_VALIDATED,
            self::STATUS_EXPORTED,
            self::STATUS_REJECTED,
        ], true);
    }

    // --- Scopes ---

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeValidated($query)
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    public function scopeExported($query)
    {
        return $query->where('status', self::STATUS_EXPORTED);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeForPeriod($query, string $periodMonth)
    {
        return $query->where('period_month', $periodMonth);
    }
}
