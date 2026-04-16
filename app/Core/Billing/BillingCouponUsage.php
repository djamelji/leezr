<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCouponUsage extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'company_id',
        'invoice_id',
        'applied_at',
        'discount_amount',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'discount_amount' => 'integer',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(BillingCoupon::class, 'coupon_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
