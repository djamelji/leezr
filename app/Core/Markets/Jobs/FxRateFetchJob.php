<?php

namespace App\Core\Markets\Jobs;

use App\Core\Markets\FxRate;
use App\Core\Markets\Market;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FxRateFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $currencies = Market::active()
            ->pluck('currency')
            ->unique()
            ->values()
            ->all();

        if (count($currencies) < 2) {
            return;
        }

        // Stub source — hardcoded rates (replace with real API later)
        $stubRates = $this->stubRates();

        foreach ($currencies as $base) {
            foreach ($currencies as $target) {
                if ($base === $target) {
                    continue;
                }

                $rate = $stubRates["{$base}/{$target}"] ?? null;

                if ($rate === null) {
                    continue;
                }

                FxRate::updateOrCreate(
                    ['base_currency' => $base, 'target_currency' => $target],
                    ['rate' => $rate, 'fetched_at' => now()],
                );
            }
        }
    }

    /**
     * Stub exchange rates. Replace with a real FX source (e.g. ECB, Open Exchange Rates).
     */
    private function stubRates(): array
    {
        return [
            'EUR/USD' => 1.0850,
            'USD/EUR' => 0.9217,
            'EUR/GBP' => 0.8580,
            'GBP/EUR' => 1.1655,
            'USD/GBP' => 0.7907,
            'GBP/USD' => 1.2647,
            'EUR/CHF' => 0.9420,
            'CHF/EUR' => 1.0616,
            'USD/CHF' => 0.8682,
            'CHF/USD' => 1.1518,
            'EUR/CAD' => 1.4720,
            'CAD/EUR' => 0.6793,
            'USD/CAD' => 1.3570,
            'CAD/USD' => 0.7369,
            'EUR/JPY' => 162.50,
            'JPY/EUR' => 0.006154,
            'USD/JPY' => 149.80,
            'JPY/USD' => 0.006676,
        ];
    }
}
