<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeTaxProfile stores PAS (prelevement a la source) tax rates per employee.
 *
 * Tax rates are stored in basis points (bps): 1200 bps = 12.00%.
 * NO social_security_number field — SSN is managed via the Fields system.
 */
class EmployeeTaxProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_employee_tax_profiles';

    protected $fillable = [
        'company_id',
        'employee_id',
        'tax_rate_bps',
        'tax_rate_source',
        'tax_domicile',
        'effective_from',
        'effective_until',
        'metadata',
    ];

    protected $casts = [
        'tax_rate_bps' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'metadata' => 'array',
    ];

    // --- Relationships ---

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // --- Scopes ---

    public function scopeActiveAt($query, $date = null)
    {
        $date ??= now();

        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }
}
