<?php

namespace Database\Seeders;

use App\Core\Markets\MarketRuleSet;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ⚠️ INDICATIF — NON EXHAUSTIF
 * Barème simplifié basé sur les taux URSSAF 2026 publics.
 * Validation par expert-comptable requise avant usage en production.
 * Ne couvre pas : conventions collectives, régimes spéciaux, tranches spécifiques.
 *
 * bulletin_group = groupement bulletin clarifié FR (arrêté 25/02/2016 modifié).
 * Purement informatif — n'affecte pas le calcul des cotisations.
 */
class WorkforcePayrollRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // ══════════════════════════════════════
            // SANTÉ
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_urssaf_maladie',
                'value' => [
                    'code' => 'urssaf_maladie',
                    'label' => 'Assurance maladie',
                    'category' => 'urssaf',
                    'bulletin_group' => 'sante',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 700,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème cotisations 2026 (indicatif)',
            ],

            // ══════════════════════════════════════
            // ACCIDENTS DU TRAVAIL / MALADIES PROF.
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_at_mp',
                'value' => [
                    'code' => 'at_mp',
                    'label' => 'Accidents du travail / Maladies professionnelles',
                    'category' => 'urssaf',
                    'bulletin_group' => 'at_mp',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 120,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Taux AT/MP moyen indicatif (varie par secteur CARSAT)',
            ],

            // ══════════════════════════════════════
            // RETRAITE
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_urssaf_vieillesse_plaf',
                'value' => [
                    'code' => 'urssaf_vieillesse_plaf',
                    'label' => 'Assurance vieillesse plafonnée',
                    'category' => 'urssaf',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'plafonne_ss',
                    'employee_rate_bps' => 690,
                    'employer_rate_bps' => 855,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème cotisations 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_urssaf_vieillesse_deplaf',
                'value' => [
                    'code' => 'urssaf_vieillesse_deplaf',
                    'label' => 'Assurance vieillesse déplafonnée',
                    'category' => 'urssaf',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 40,
                    'employer_rate_bps' => 190,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème cotisations 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_retraite_t1',
                'value' => [
                    'code' => 'retraite_t1',
                    'label' => 'Retraite complémentaire tranche 1',
                    'category' => 'retraite',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'tranche_1',
                    'employee_rate_bps' => 386,
                    'employer_rate_bps' => 604,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'agirc-arrco.fr',
                'reference' => 'Barème Agirc-Arrco 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_retraite_t2',
                'value' => [
                    'code' => 'retraite_t2',
                    'label' => 'Retraite complémentaire tranche 2',
                    'category' => 'retraite',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'tranche_2',
                    'employee_rate_bps' => 862,
                    'employer_rate_bps' => 1362,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'agirc-arrco.fr',
                'reference' => 'Barème Agirc-Arrco 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_ceg_t1',
                'value' => [
                    'code' => 'ceg_t1',
                    'label' => 'CEG Tranche 1',
                    'category' => 'retraite',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'plafonne_ss',
                    'employee_rate_bps' => 86,
                    'employer_rate_bps' => 129,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'agirc-arrco.fr',
                'reference' => 'Barème CEG 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_ceg_t2',
                'value' => [
                    'code' => 'ceg_t2',
                    'label' => 'CEG Tranche 2',
                    'category' => 'retraite',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'tranche_2',
                    'employee_rate_bps' => 108,
                    'employer_rate_bps' => 162,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'agirc-arrco.fr',
                'reference' => 'Barème CEG 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_cet',
                'value' => [
                    'code' => 'cet',
                    'label' => 'CET',
                    'category' => 'retraite',
                    'bulletin_group' => 'retraite',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 14,
                    'employer_rate_bps' => 21,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'agirc-arrco.fr',
                'reference' => 'Barème CET 2026 (indicatif)',
            ],

            // ══════════════════════════════════════
            // FAMILLE
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_allocations_familiales',
                'value' => [
                    'code' => 'allocations_familiales',
                    'label' => 'Allocations familiales',
                    'category' => 'famille',
                    'bulletin_group' => 'famille',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 525,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Taux unique 5.25% LFSS 2025 (indicatif)',
            ],

            // ══════════════════════════════════════
            // ASSURANCE CHÔMAGE
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_chomage',
                'value' => [
                    'code' => 'chomage',
                    'label' => 'Assurance chômage',
                    'category' => 'chomage',
                    'bulletin_group' => 'chomage',
                    'base_type' => 'plafonne_ss',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 405,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème cotisations 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_ags',
                'value' => [
                    'code' => 'ags',
                    'label' => 'AGS',
                    'category' => 'chomage',
                    'bulletin_group' => 'chomage',
                    'base_type' => 'plafonne_ss',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 20,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème AGS 2026 (indicatif — plafond réel 4×PMSS)',
            ],

            // ══════════════════════════════════════
            // CSG / CRDS
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_csg_deductible',
                'value' => [
                    'code' => 'csg_deductible',
                    'label' => 'CSG déductible',
                    'category' => 'csg_crds',
                    'bulletin_group' => 'csg_crds',
                    'base_type' => 'csg_base',
                    'employee_rate_bps' => 681,
                    'employer_rate_bps' => 0,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème CSG 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_csg_non_deductible',
                'value' => [
                    'code' => 'csg_non_deductible',
                    'label' => 'CSG non déductible',
                    'category' => 'csg_crds',
                    'bulletin_group' => 'csg_crds',
                    'base_type' => 'csg_base',
                    'employee_rate_bps' => 242,
                    'employer_rate_bps' => 0,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème CSG 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_crds',
                'value' => [
                    'code' => 'crds',
                    'label' => 'CRDS',
                    'category' => 'csg_crds',
                    'bulletin_group' => 'csg_crds',
                    'base_type' => 'csg_base',
                    'employee_rate_bps' => 50,
                    'employer_rate_bps' => 0,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème CRDS 2026 (indicatif)',
            ],

            // ══════════════════════════════════════
            // AUTRES CONTRIBUTIONS EMPLOYEUR
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_fnal',
                'value' => [
                    'code' => 'fnal',
                    'label' => 'FNAL',
                    'category' => 'autres_employeur',
                    'bulletin_group' => 'autres_employeur',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 50,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Taux FNAL 0.50% ≥50 sal. (indicatif — 0.10% <50 sal. T1)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_csa',
                'value' => [
                    'code' => 'csa',
                    'label' => 'Contribution solidarité autonomie',
                    'category' => 'autres_employeur',
                    'bulletin_group' => 'autres_employeur',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 30,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème CSA 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_formation_pro',
                'value' => [
                    'code' => 'formation_pro',
                    'label' => 'Formation professionnelle',
                    'category' => 'autres_employeur',
                    'bulletin_group' => 'autres_employeur',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 100,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Taux 1.00% ≥11 sal. (indicatif — 0.55% <11 sal.)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_taxe_apprentissage',
                'value' => [
                    'code' => 'taxe_apprentissage',
                    'label' => "Taxe d'apprentissage",
                    'category' => 'autres_employeur',
                    'bulletin_group' => 'autres_employeur',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 68,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Barème taxe apprentissage 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'contribution_dialogue_social',
                'value' => [
                    'code' => 'dialogue_social',
                    'label' => 'Contribution au dialogue social',
                    'category' => 'autres_employeur',
                    'bulletin_group' => 'autres_employeur',
                    'base_type' => 'deplafonne',
                    'employee_rate_bps' => 0,
                    'employer_rate_bps' => 2,
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Taux 0.016% (indicatif — arrondi 2 bps)',
            ],

            // ══════════════════════════════════════
            // META RULES (non-contribution)
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'csg_base_multiplier',
                'value' => [
                    'multiplier_bps' => 9825,
                    'label' => 'Abattement 1.75% pour frais professionnels',
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Assiette CSG/CRDS 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'plafond_ss_monthly_cents',
                'value' => [
                    'amount' => 383400,
                    'currency' => 'EUR',
                    'label' => 'Plafond mensuel de la Sécurité sociale 2026',
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'PMSS 2026 (indicatif)',
            ],

            // ══════════════════════════════════════
            // RGDU — Réduction Générale (Sprint 6.1)
            // ══════════════════════════════════════
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'smic_horaire_cents',
                'value' => [
                    'amount' => 1147,
                    'currency' => 'EUR',
                    'label' => 'SMIC horaire brut 2026',
                ],
                'effective_from' => '2026-01-01',
                'source' => 'legifrance.gouv.fr',
                'reference' => 'SMIC horaire brut 2026 (indicatif — 11,47 €)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'rgdu_coefficient_t',
                'value' => [
                    'less_than_50_bps' => 3195,
                    'fifty_or_more_bps' => 3235,
                    'label' => 'Coefficient T maximal RGDU (art. D241-7 CSS)',
                ],
                'effective_from' => '2026-01-01',
                'source' => 'legifrance.gouv.fr',
                'reference' => 'Coefficient T réduction générale 2026 (indicatif)',
            ],
            [
                'market_key' => 'FR',
                'domain' => 'workforce_payroll',
                'rule_key' => 'rgdu_eligible_codes',
                'value' => [
                    'codes' => [
                        'urssaf_maladie',
                        'at_mp',
                        'allocations_familiales',
                        'urssaf_vieillesse_plaf',
                        'urssaf_vieillesse_deplaf',
                        'fnal',
                        'csa',
                        'chomage',
                        'retraite_t1',
                        'ceg_t1',
                        'cet',
                    ],
                    'label' => 'Codes cotisations patronales éligibles à la RGDU',
                ],
                'effective_from' => '2026-01-01',
                'source' => 'urssaf.fr',
                'reference' => 'Périmètre réduction générale 2026 (indicatif)',
            ],
        ];

        foreach ($rules as $rule) {
            $rule['effective_from'] = Carbon::parse($rule['effective_from']);
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
