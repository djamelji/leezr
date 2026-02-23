<?php

namespace App\Core\Markets\ReadModels;

use App\Core\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MarketDetailReadModel
{
    public static function companiesForMarket(string $marketKey, int $perPage = 15): LengthAwarePaginator
    {
        return Company::where('market_key', $marketKey)
            ->select('id', 'name', 'slug', 'status', 'plan_key', 'market_key', 'legal_status_key', 'created_at')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
