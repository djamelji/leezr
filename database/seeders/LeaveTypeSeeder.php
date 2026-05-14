<?php

namespace Database\Seeders;

use App\Core\Workforce\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Default: seed for all companies that don't have system leave types yet.
        // For targeted seeding, use seedForCompany() directly.
    }

    /**
     * Seed system leave types for a specific company.
     * Called during registration or manually via artisan tinker.
     */
    public static function seedForCompany(int $companyId, string $marketKey = 'FR'): void
    {
        $types = self::typesForMarket($marketKey);

        foreach ($types as $type) {
            LeaveType::withoutCompanyScope()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'code' => $type['code'],
                ],
                array_merge($type, [
                    'company_id' => $companyId,
                    'is_system' => true,
                    'market_key' => $marketKey,
                    'enabled' => true,
                ]),
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function typesForMarket(string $marketKey): array
    {
        return match ($marketKey) {
            'FR' => self::frenchTypes(),
            default => self::frenchTypes(), // fallback to FR
        };
    }

    /**
     * French market system leave types.
     *
     * All day amounts are in hundredths (1 day = 100).
     * Code du travail references:
     *   - Congés payés: L3141-3 (25 jours ouvrés / an)
     *   - RTT: L3121-44 (forfait jours, accords d'entreprise)
     *   - Congé maladie: L1226-1
     *   - Congé maternité: L1225-17 (16 semaines minimum)
     *   - Congé paternité: L1225-35 (25 jours calendaires)
     *   - Congé sans solde: jurisprudence / convention collective
     *
     * @return array<int, array<string, mixed>>
     */
    private static function frenchTypes(): array
    {
        return [
            [
                'code' => 'annual_leave',
                'name' => 'Congés payés',
                'description' => '25 jours ouvrés par an (Code du travail L3141-3)',
                'accrual_mode' => LeaveType::ACCRUAL_MONTHLY,
                'annual_entitlement_hundredths' => 2500,
                'max_balance_hundredths' => 3000,
                'carry_over_hundredths' => 500,
                'carry_over_deadline_month' => 3,
                'requires_approval' => true,
                'is_paid' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'rtt',
                'name' => 'RTT',
                'description' => 'Réduction du temps de travail (12 jours/an)',
                'accrual_mode' => LeaveType::ACCRUAL_MONTHLY,
                'annual_entitlement_hundredths' => 1200,
                'max_balance_hundredths' => 1200,
                'carry_over_hundredths' => 0,
                'carry_over_deadline_month' => null,
                'requires_approval' => true,
                'is_paid' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'sick_leave',
                'name' => 'Congé maladie',
                'description' => 'Arrêt maladie avec justificatif médical',
                'accrual_mode' => LeaveType::ACCRUAL_NONE,
                'annual_entitlement_hundredths' => 0,
                'max_balance_hundredths' => null,
                'carry_over_hundredths' => 0,
                'carry_over_deadline_month' => null,
                'requires_approval' => true,
                'is_paid' => true,
                'sort_order' => 30,
            ],
            [
                'code' => 'maternity',
                'name' => 'Congé maternité',
                'description' => 'Congé maternité légal (16 semaines minimum)',
                'accrual_mode' => LeaveType::ACCRUAL_NONE,
                'annual_entitlement_hundredths' => 0,
                'max_balance_hundredths' => null,
                'carry_over_hundredths' => 0,
                'carry_over_deadline_month' => null,
                'requires_approval' => true,
                'is_paid' => true,
                'sort_order' => 40,
            ],
            [
                'code' => 'paternity',
                'name' => 'Congé paternité',
                'description' => 'Congé paternité (25 jours calendaires)',
                'accrual_mode' => LeaveType::ACCRUAL_NONE,
                'annual_entitlement_hundredths' => 0,
                'max_balance_hundredths' => null,
                'carry_over_hundredths' => 0,
                'carry_over_deadline_month' => null,
                'requires_approval' => true,
                'is_paid' => true,
                'sort_order' => 50,
            ],
            [
                'code' => 'unpaid',
                'name' => 'Congé sans solde',
                'description' => 'Congé non rémunéré, soumis à approbation',
                'accrual_mode' => LeaveType::ACCRUAL_NONE,
                'annual_entitlement_hundredths' => 0,
                'max_balance_hundredths' => null,
                'carry_over_hundredths' => 0,
                'carry_over_deadline_month' => null,
                'requires_approval' => true,
                'is_paid' => false,
                'sort_order' => 60,
            ],
        ];
    }
}
