<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDashboardLayout extends Model
{
    protected $fillable = ['company_id', 'user_id', 'company_role_id', 'layout_json'];

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

    public function companyRole(): BelongsTo
    {
        return $this->belongsTo(\App\Company\RBAC\CompanyRole::class);
    }

    /**
     * Resolve layout for a specific user in a company (ADR-326, ADR-357).
     *
     * Priority: user-specific → role-specific → company default → null.
     */
    public static function resolveForUser(int $companyId, int $userId, ?int $companyRoleId = null): ?self
    {
        // 1. User-specific layout
        $userLayout = static::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
        if ($userLayout) {
            return $userLayout;
        }

        // 2. Role-specific default
        if ($companyRoleId) {
            $roleLayout = static::where('company_id', $companyId)
                ->whereNull('user_id')
                ->where('company_role_id', $companyRoleId)
                ->first();
            if ($roleLayout) {
                return $roleLayout;
            }
        }

        // 3. Company default (user_id=NULL, company_role_id=NULL)
        return static::where('company_id', $companyId)
            ->whereNull('user_id')
            ->whereNull('company_role_id')
            ->first();
    }
}
