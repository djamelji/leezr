<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\ScheduleTemplate;
use Illuminate\Support\Collection;

class ScheduleTemplateReadModel
{
    public static function forCompany(int $companyId): Collection
    {
        return ScheduleTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->enabled()
            ->with('workLocation')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public static function all(int $companyId): Collection
    {
        return ScheduleTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('workLocation')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
