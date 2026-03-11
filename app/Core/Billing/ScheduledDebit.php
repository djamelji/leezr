<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-328 S2: Scheduled SEPA debit — deferred payment collection.
 *
 * Lifecycle: pending → processing → collected|failed|cancelled
 */
class ScheduledDebit extends Model
{
    protected $fillable = [
        'invoice_id', 'company_id', 'payment_profile_id',
        'amount', 'currency', 'debit_date', 'status',
        'processed_at', 'failure_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'debit_date' => 'date',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(CompanyPaymentProfile::class, 'payment_profile_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('debit_date', '<=', now()->toDateString());
    }
}
