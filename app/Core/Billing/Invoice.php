<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'subscription_id', 'parent_invoice_id', 'company_id', 'number', 'annexe_suffix',
        'amount', 'subtotal', 'tax_amount', 'tax_rate_bps', 'tax_exemption_reason',
        'wallet_credit_applied', 'amount_due', 'coupon_id',
        'currency', 'status', 'provider', 'provider_invoice_id',
        'period_start', 'period_end', 'billing_snapshot',
        'issued_at', 'due_at', 'paid_at',
        'finalized_at', 'voided_at',
        'retry_count', 'next_retry_at',
        'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'tax_rate_bps' => 'integer',
            'wallet_credit_applied' => 'integer',
            'amount_due' => 'integer',
            'retry_count' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'billing_snapshot' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected $appends = ['display_number'];

    public function getDisplayNumberAttribute(): string
    {
        return $this->displayNumber();
    }

    // ── Relationships ──

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    public function annexes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
    }

    // ── Accessors ──

    /**
     * ADR-328: Display number includes annexe suffix when present.
     * Example: INV-2026-000001-A
     */
    public function displayNumber(): string
    {
        if ($this->annexe_suffix && $this->parent_invoice_id) {
            $parentNumber = $this->relationLoaded('parentInvoice')
                ? $this->parentInvoice->number
                : self::where('id', $this->parent_invoice_id)->value('number');

            return $parentNumber . '-' . $this->annexe_suffix;
        }

        return $this->number ?? '';
    }

    public function isAnnexe(): bool
    {
        return $this->parent_invoice_id !== null;
    }

    // ── Guards ──

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function isMutable(): bool
    {
        return !$this->isFinalized();
    }
}
