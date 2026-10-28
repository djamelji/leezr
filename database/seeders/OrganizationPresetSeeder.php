<?php

namespace Database\Seeders;

use App\Modules\Workforce\Organization\OrganizationPreset;
use Illuminate\Database\Seeder;

/**
 * ADR-636 (W-E) — presets organisationnels de démonstration pour un JobDomain RÉELLEMENT déclaré
 * (`logistique`). Idempotent. Ne simule aucun JobDomain/Market non enregistré.
 */
class OrganizationPresetSeeder extends Seeder
{
    public function run(): void
    {
        // Livraison dernier kilomètre (logistique, FR, profil last_mile_delivery) — PUBLIÉ.
        OrganizationPreset::updateOrCreate(
            ['key' => 'logistique_last_mile_v1'],
            [
                'label' => 'Livraison dernier kilomètre',
                'jobdomain_key' => 'logistique',
                'market_key' => 'FR',
                'org_profile_key' => 'last_mile_delivery',
                'version' => 1,
                'status' => 'published',
                'published_at' => now(),
                'description' => 'Organisation type pour une activité de livraison dernier kilomètre.',
                'structure' => [
                    'departments' => [
                        ['key' => 'direction', 'name' => 'Direction', 'parent_key' => null, 'sort_order' => 0],
                        ['key' => 'exploitation', 'name' => 'Exploitation', 'parent_key' => 'direction', 'sort_order' => 1],
                        ['key' => 'administration', 'name' => 'Administration', 'parent_key' => 'direction', 'sort_order' => 2],
                    ],
                    'job_roles' => [
                        ['key' => 'ops_manager', 'title' => "Responsable d'exploitation", 'department_key' => 'exploitation', 'level' => 'manager'],
                        ['key' => 'planner', 'title' => 'Planification', 'department_key' => 'exploitation', 'level' => 'agent'],
                        ['key' => 'driver', 'title' => 'Livreur', 'department_key' => 'exploitation', 'level' => 'agent'],
                        ['key' => 'admin_officer', 'title' => 'Gestionnaire administratif', 'department_key' => 'administration', 'level' => 'agent'],
                    ],
                ],
            ],
        );

        // Base jobdomain (sans profil, sans market) — fallback minimal.
        OrganizationPreset::updateOrCreate(
            ['key' => 'logistique_base_v1'],
            [
                'label' => 'Organisation logistique de base',
                'jobdomain_key' => 'logistique', 'market_key' => null, 'org_profile_key' => null,
                'version' => 1, 'status' => 'published', 'published_at' => now(),
                'description' => 'Structure minimale pour toute entreprise logistique.',
                'structure' => [
                    'departments' => [
                        ['key' => 'direction', 'name' => 'Direction', 'parent_key' => null, 'sort_order' => 0],
                        ['key' => 'exploitation', 'name' => 'Exploitation', 'parent_key' => 'direction', 'sort_order' => 1],
                    ],
                    'job_roles' => [
                        ['key' => 'ops_manager', 'title' => "Responsable d'exploitation", 'department_key' => 'exploitation', 'level' => 'manager'],
                    ],
                ],
            ],
        );
    }
}
