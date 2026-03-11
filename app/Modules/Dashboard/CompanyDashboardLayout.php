<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDashboardLayout extends Model
{
    protected $fillable = ['company_id', 'user_id', 'layout_json'];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve layout for a specific user in a company (ADR-326).
     *
     * Priority: user-specific → company default (user_id=NULL) → null.
     */
    public static function resolveForUser(int $companyId, int $userId): ?self
    {
        return static::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first()
            ?? static::where('company_id', $companyId)
                ->whereNull('user_id')
                ->first();
    }
}
