<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = [
        'company_id',
        'created_by_user_id',
        'reference',
        'status',
        'origin_address',
        'destination_address',
        'scheduled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    // --- Status constants ---

    const STATUS_DRAFT = 'draft';
    const STATUS_PLANNED = 'planned';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELED = 'canceled';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PLANNED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELED,
    ];

    const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_CANCELED,
    ];

    /**
     * Valid transitions: current_status => [allowed_next_statuses]
     */
    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PLANNED, self::STATUS_CANCELED],
        self::STATUS_PLANNED => [self::STATUS_IN_TRANSIT, self::STATUS_CANCELED],
        self::STATUS_IN_TRANSIT => [self::STATUS_DELIVERED, self::STATUS_CANCELED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELED => [],
    ];

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // --- Business logic ---

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Generate the next reference for a company on a given date.
     * Format: SHP-YYYYMMDD-XXXX
     */
    public static function generateReference(int $companyId, ?\DateTimeInterface $date = null): string
    {
        $date = $date ?? now();
        $dateStr = $date->format('Ymd');
        $prefix = "SHP-{$dateStr}-";

        $lastRef = static::where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}%")
            ->orderByDesc('reference')
            ->value('reference');

        if ($lastRef) {
            $lastNumber = (int) substr($lastRef, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
