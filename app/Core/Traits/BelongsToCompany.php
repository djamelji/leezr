<?php

namespace App\Core\Traits;

use App\Core\Models\Company;
use App\Core\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-432: Trait for models with a non-nullable company_id column.
 *
 * Provides:
 * - Automatic CompanyScope (filters by company.context when bound)
 * - Auto-fill company_id on creating (if context bound and not already set)
 * - company() relationship
 * - withoutCompanyScope() convenience query
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (Model $model) {
            if (empty($model->company_id) && app()->bound('company.context')) {
                $model->company_id = app('company.context')->id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Query without the company scope (for platform/cross-tenant queries).
     */
    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }
}
