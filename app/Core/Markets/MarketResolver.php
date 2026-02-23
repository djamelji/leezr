<?php

namespace App\Core\Markets;

use App\Core\Models\Company;

/**
 * Resolves the applicable market for a given context.
 *
 * - resolveForCompany(): returns the company's market or platform default.
 * - resolveDefault(): returns the platform's default market.
 * - fallback(): in-memory US market when no DB data exists (migration phase).
 *
 * ADR-104: International Market Engine.
 */
class MarketResolver
{
    public static function resolveForCompany(Company $company): Market
    {
        if ($company->market_key) {
            $market = Market::where('key', $company->market_key)->first();

            if ($market) {
                return $market;
            }
        }

        return static::resolveDefault();
    }

    public static function resolveDefault(): Market
    {
        try {
            $market = Market::where('is_default', true)->first()
                ?? Market::active()->orderBy('sort_order')->first();
        } catch (\Throwable) {
            return static::fallback();
        }

        return $market ?? static::fallback();
    }

    /**
     * In-memory fallback when no markets exist in DB (migration phase).
     */
    private static function fallback(): Market
    {
        return new Market([
            'key' => 'US',
            'name' => 'United States',
            'currency' => 'USD',
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
            'dial_code' => '+1',
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
