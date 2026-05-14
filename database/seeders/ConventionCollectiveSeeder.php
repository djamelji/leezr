<?php

namespace Database\Seeders;

use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use Illuminate\Database\Seeder;

/**
 * ⚠️ INDICATIF — NON EXHAUSTIF
 * Données de test CC Métallurgie (IDCC 3248) simplifiées.
 * Validation par expert-comptable requise avant usage en production.
 */
class ConventionCollectiveSeeder extends Seeder
{
    public function run(): void
    {
        $cc = ConventionCollective::updateOrCreate(
            ['idcc' => '3248', 'market_key' => 'FR'],
            [
                'short_name' => 'Métallurgie',
                'full_name' => 'Convention collective nationale de la métallurgie',
                'brochure_number' => '3109',
                'is_active' => true,
                'metadata' => [
                    'effective_date' => '2024-01-01',
                    'legifrance_url' => 'https://www.legifrance.gouv.fr/conv_coll/id/KALICONT000046993250',
                ],
            ]
        );

        $rules = [
            // --- Cotisation : Mutuelle obligatoire CC ---
            [
                'domain' => 'workforce_payroll',
                'rule_type' => 'contribution',
                'rule_key' => 'contribution_cc_mutuelle',
                'value' => [
                    'code' => 'cc_mutuelle',
                    'label' => 'Mutuelle obligatoire CC Métallurgie',
                    'category' => 'complementaire',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 50,   // 0.50% salarié
                    'employer_rate_bps' => 100,  // 1.00% employeur
                ],
                'effective_from' => '2026-01-01',
                'override_mode' => 'supplement',
                'source' => 'CC Métallurgie Art. 72',
                'reference' => 'Couverture frais de santé obligatoire (indicatif)',
            ],
            // --- Cotisation : Prévoyance CC ---
            [
                'domain' => 'workforce_payroll',
                'rule_type' => 'contribution',
                'rule_key' => 'contribution_cc_prevoyance',
                'value' => [
                    'code' => 'cc_prevoyance',
                    'label' => 'Prévoyance CC Métallurgie',
                    'category' => 'complementaire',
                    'base_type' => 'plafonne_ss',
                    'employee_rate_bps' => 30,   // 0.30% salarié
                    'employer_rate_bps' => 120,  // 1.20% employeur
                ],
                'effective_from' => '2026-01-01',
                'override_mode' => 'supplement',
                'source' => 'CC Métallurgie Art. 73',
                'reference' => 'Régime de prévoyance obligatoire (indicatif)',
            ],
            // --- Benefit : Prime ancienneté (modèle, non résolu par payroll en 4.1) ---
            [
                'domain' => 'workforce_payroll',
                'rule_type' => 'seniority_bonus',
                'rule_key' => 'seniority_bonus_cc_metallurgie',
                'value' => [
                    'label' => 'Prime ancienneté CC Métallurgie',
                    'tiers' => [
                        ['min_years' => 3, 'rate_bps' => 200],   // +2% après 3 ans
                        ['min_years' => 6, 'rate_bps' => 400],   // +4% après 6 ans
                        ['min_years' => 9, 'rate_bps' => 600],   // +6% après 9 ans
                        ['min_years' => 12, 'rate_bps' => 800],  // +8% après 12 ans
                        ['min_years' => 15, 'rate_bps' => 1500], // +15% après 15 ans
                    ],
                ],
                'effective_from' => '2026-01-01',
                'override_mode' => 'replace',
                'source' => 'CC Métallurgie Art. 65',
                'reference' => 'Prime d\'ancienneté conventionnelle (indicatif)',
            ],
        ];

        foreach ($rules as $rule) {
            ConventionCollectiveRule::updateOrCreate(
                [
                    'convention_collective_id' => $cc->id,
                    'domain' => $rule['domain'],
                    'rule_type' => $rule['rule_type'],
                    'rule_key' => $rule['rule_key'],
                    'effective_from' => $rule['effective_from'],
                ],
                $rule
            );
        }
    }
}
