<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Markets\SvgSanitizer;
use Illuminate\Support\Facades\DB;

class UpsertMarketUseCase
{
    public function execute(UpsertMarketData $data): Market
    {
        $attributes = [
            'key' => $data->key,
            'name' => $data->name,
            'currency' => $data->currency,
            'locale' => $data->locale,
            'timezone' => $data->timezone,
            'dial_code' => $data->dialCode,
            'flag_code' => $data->flagCode,
            'flag_svg' => $data->flagSvg ? SvgSanitizer::sanitize($data->flagSvg) : $data->flagSvg,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ];

        // Atomicité : flip du défaut + create/update + sync des langues ensemble.
        // Sinon un échec partiel peut laisser zéro marché par défaut ou un marché sans langues.
        $market = DB::transaction(function () use ($data, $attributes) {
            if ($data->id) {
                // Update existing
                $market = Market::findOrFail($data->id);
                $market->update($attributes);
            } else {
                // Create new — handle default invariant
                if ($data->isDefault) {
                    Market::where('is_default', true)->update(['is_default' => false]);
                    $attributes['is_default'] = true;
                }
                $market = Market::create($attributes);
            }

            // Sync languages if provided
            if ($data->languageKeys !== null) {
                $market->languages()->sync(
                    array_combine($data->languageKeys, array_fill(0, count($data->languageKeys), []))
                );
            }

            return $market;
        });

        MarketRegistry::clearCache();

        return $market->load(['legalStatuses', 'languages']);
    }
}
