<?php

namespace App\Core\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * ADR-432: Global scope that automatically filters queries by company_id
 * when a company context is bound in the container.
 *
 * No-op when no context is bound (platform, artisan, queue without context).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('company.context')) {
            $builder->where(
                $model->qualifyColumn('company_id'),
                app('company.context')->id
            );
        }
    }
}
