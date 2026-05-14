<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\WorkLocation;
use Illuminate\Support\Collection;

class WorkLocationReadModel
{
    public static function forCompany(int $companyId): Collection
    {
        return WorkLocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->enabled()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public static function all(int $companyId): Collection
    {
        return WorkLocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
