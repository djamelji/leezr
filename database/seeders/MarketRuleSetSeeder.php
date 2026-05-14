<?php

namespace Database\Seeders;

use App\Core\Markets\MarketRuleSet;
use Illuminate\Database\Seeder;

class MarketRuleSetSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'weekly_hours_legal',
                'value' => ['amount' => 35, 'unit' => 'hours'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3121-27',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'daily_hours_max',
                'value' => ['amount' => 10, 'unit' => 'hours'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3121-18',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'weekly_hours_max',
                'value' => ['amount' => 48, 'unit' => 'hours'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3121-20',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'rest_daily_min',
                'value' => ['amount' => 11, 'unit' => 'hours'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3131-1',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'rest_weekly_min',
                'value' => ['amount' => 35, 'unit' => 'hours'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3132-2',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'break_after_hours',
                'value' => ['amount' => 6, 'unit' => 'hours', 'break_min' => 20, 'break_unit' => 'minutes'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3121-16',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'annual_leave_days',
                'value' => ['amount' => 25, 'unit' => 'days', 'basis' => 'working_days'],
                'effective_from' => '2000-01-01',
                'source' => 'law',
                'reference' => 'Code du travail L3141-3',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce',
                'rule_key' => 'min_wage_hourly_cents',
                'value' => ['amount' => 1178, 'currency' => 'EUR'],
                'effective_from' => '2026-01-01',
                'source' => 'law',
                'reference' => 'Décret D3231-3 (2026)',
            ],
        ];

        foreach ($rules as $rule) {
            MarketRuleSet::updateOrCreate(
                [
                    'market_key' => $rule['market_key'],
                    'domain' => $rule['domain'],
                    'rule_key' => $rule['rule_key'],
                    'effective_from' => $rule['effective_from'],
                ],
                $rule,
            );
        }
    }
}
