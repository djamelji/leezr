<?php

namespace App\Core\Scopes;

use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * ADR-432: Global scope that automatically filters queries by company_id
 * when a company context is bound in the container.
 *
 * No-op when no context is bound (platform, artisan, queue without context).
 *
 * ADR-733 (P0-D): en mode strict OPT-IN (`CompanyContext::$strict`), une requête tenant-scopée sans
 * contexte et hors d'un bloc `runGlobal()` LÈVE au lieu de lire tous les tenants (fail-closed). Jamais
 * activé globalement (casserait les jobs legacy qui s'appuient sur le no-op) ; réservé aux tests et aux
 * déploiements migrés. Les requêtes `withoutGlobalScopes()` n'atteignent pas ce scope → non concernées.
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

            return;
        }

        if (CompanyContext::$strict && ! CompanyContext::isGlobal()) {
            throw new MissingTenantContextException(get_class($model));
        }
    }
}
