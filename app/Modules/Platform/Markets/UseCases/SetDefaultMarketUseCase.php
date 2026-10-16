<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetDefaultMarketUseCase
{
    public function execute(int $id): Market
    {
        $market = Market::findOrFail($id);

        if (!$market->is_active) {
            throw ValidationException::withMessages([
                'market' => 'Cannot set inactive market as default.',
            ]);
        }

        // Invariant: only one default market — flip atomique (sinon zéro défaut si échec partiel)
        DB::transaction(function () use ($market) {
            Market::where('is_default', true)->update(['is_default' => false]);
            $market->update(['is_default' => true]);
        });

        MarketRegistry::clearCache();

        return $market->load(['legalStatuses', 'languages']);
    }
}
