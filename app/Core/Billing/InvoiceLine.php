<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'invoice_id', 'type', 'module_key', 'description',
        'quantity', 'unit_amount', 'amount',
        'period_start', 'period_end',
        'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
