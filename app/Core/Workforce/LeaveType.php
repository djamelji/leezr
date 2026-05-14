<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_leave_types';

    const ACCRUAL_MONTHLY = 'monthly';
    const ACCRUAL_ANNUAL = 'annual';
    const ACCRUAL_NONE = 'none';
    const ACCRUAL_CUSTOM = 'custom';

    const ACCRUAL_MODES = [
        self::ACCRUAL_MONTHLY,
        self::ACCRUAL_ANNUAL,
        self::ACCRUAL_NONE,
        self::ACCRUAL_CUSTOM,
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'accrual_mode',
        'annual_entitlement_hundredths',
        'max_balance_hundredths',
        'carry_over_hundredths',
        'carry_over_deadline_month',
        'requires_approval',
        'is_paid',
        'is_system',
        'market_key',
        'sort_order',
        'enabled',
        'metadata',
    ];

    protected $casts = [
        'annual_entitlement_hundredths' => 'integer',
        'max_balance_hundredths' => 'integer',
        'carry_over_hundredths' => 'integer',
        'carry_over_deadline_month' => 'integer',
        'requires_approval' => 'boolean',
        'is_paid' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class, 'leave_type_id');
    }

    // ── Scopes ──

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeForCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
}
