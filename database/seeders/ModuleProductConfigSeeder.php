<?php

namespace Database\Seeders;

use App\Core\Modules\PlatformModule;
use Illuminate\Database\Seeder;

/**
 * Commercial/productization config for sellable add-on modules (display name, icon,
 * is_sellable, addon_pricing). This is PRODUCT reference data — NOT tenant/demo data —
 * so it must be seeded in production too.
 *
 * Audit 13 (DEV/PROD divergence): this config previously lived only in DevSeeder
 * (dev/staging). On PROD it never ran → these modules had no addon_pricing →
 * EntitlementResolver returned `not_available` (display_state `contact_sales`) →
 * Workforce & logistics add-ons were UNSELLABLE and UNACTIVATABLE for real customers,
 * while staging (DevSeeder) force-activated them, masking the gap. Moving it here
 * (called by SystemSeeder) makes the catalog consistent across environments.
 *
 * Idempotent: updates existing PlatformModule rows by key (no-op if a key is absent).
 * Values are the pre-existing pilot config — none invented here.
 */
class ModuleProductConfigSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::CONFIG as $key => $attrs) {
            PlatformModule::where('key', $key)->update($attrs);
        }
    }

    private const CONFIG = [
        'logistics_tracking' => [
            'display_name_override' => 'Real-Time Tracking',
            'description_override' => 'Track shipments in real time with live geolocation updates.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-map-pin',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'flat', 'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 29],
            ],
            'notes' => 'Flat monthly add-on fee for real-time shipment tracking.',
        ],
        'logistics_fleet' => [
            'display_name_override' => 'Fleet Management',
            'description_override' => 'Manage vehicles, drivers and maintenance schedules.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-truck',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'per_seat', 'pricing_metric' => 'users',
                'pricing_params' => [
                    'included' => ['starter' => 5, 'pro' => 10, 'business' => 25],
                    'overage_unit_price' => ['starter' => 1, 'pro' => 0.8, 'business' => 0.6],
                ],
            ],
            'notes' => 'Per-seat pricing with plan-based included seats.',
        ],
        'logistics_analytics' => [
            'display_name_override' => 'Advanced Analytics',
            'description_override' => 'Operational insights and performance dashboards.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-chart-bar',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'plan_flat', 'pricing_metric' => 'none',
                'pricing_params' => ['starter' => 49, 'pro' => 29, 'business' => 19],
            ],
            'notes' => 'Additional monthly price varies by plan tier.',
        ],
        'workforce' => [
            'display_name_override' => 'RH & Effectifs',
            'description_override' => 'Gestion des employés, contrats de travail et plans de rémunération.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-users-group',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'per_seat', 'pricing_metric' => 'employees',
                'pricing_params' => [
                    'included' => ['starter' => 5, 'pro' => 15, 'business' => 50],
                    'overage_unit_price' => ['starter' => 3, 'pro' => 2, 'business' => 1],
                ],
            ],
        ],
        'workforce_leave' => [
            'display_name_override' => 'Congés & Absences',
            'description_override' => 'Suivi des congés, absences et soldes en temps réel.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-calendar-off',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'flat', 'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 19],
            ],
        ],
        'workforce_planning' => [
            'display_name_override' => 'Planning & Pointage',
            'description_override' => 'Planification des horaires et suivi du temps de travail.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-clock-hour-4',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'flat', 'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 29],
            ],
        ],
        'workforce_payroll' => [
            'display_name_override' => 'Paie & Déclarations',
            'description_override' => 'Calcul de paie, fiches de paie et déclarations DSN.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-receipt-2',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'per_seat', 'pricing_metric' => 'employees',
                'pricing_params' => [
                    'included' => ['starter' => 5, 'pro' => 15, 'business' => 50],
                    'overage_unit_price' => ['starter' => 5, 'pro' => 3, 'business' => 2],
                ],
            ],
        ],
        'workforce_documents' => [
            'display_name_override' => 'Documents Employés',
            'description_override' => 'Coffre-fort numérique et suivi documentaire des employés.',
            'icon_type' => 'tabler', 'icon_name' => 'tabler-file-certificate',
            'is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => [
                'pricing_model' => 'flat', 'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 9],
            ],
        ],
    ];
}
