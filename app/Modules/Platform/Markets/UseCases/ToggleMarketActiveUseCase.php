<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Validation\ValidationException;

class ToggleMarketActiveUseCase
{
    public function execute(int $id): Market
    {
        $market = Market::findOrFail($id);

        // Guard: cannot deactivate if companies are using this market
        if ($market->is_active) {
            $count = Company::where('market_key', $market->key)->count();

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'market' => "Cannot deactivate: {$count} companies are using this market.",
                ]);
            }
        }

        $market->update(['is_active' => !$market->is_active]);

        MarketRegistry::clearCache();

        return $market->load(['legalStatuses', 'languages']);
    }
}
