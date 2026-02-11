<?php

namespace Database\Seeders;

use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Admin owner (also platform admin)
        $admin = User::create([
            'name' => 'Djamel',
            'email' => 'admin@leezr.test',
            'password' => 'password',
            'is_platform_admin' => true,
        ]);

        $company = Company::create([
            'name' => 'Leezr Logistics',
            'slug' => 'leezr-logistics',
        ]);

        $company->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'owner',
        ]);

        // Second user (member)
        $member = User::create([
            'name' => 'Alice Martin',
            'email' => 'alice@leezr.test',
            'password' => 'password',
        ]);

        $company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'admin',
        ]);

        // Third user (simple user)
        $user = User::create([
            'name' => 'Bob Dupont',
            'email' => 'bob@leezr.test',
            'password' => 'password',
        ]);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
        ]);

        // Sync module catalog from registry
        ModuleRegistry::sync();

        // Activate all modules for the test company
        foreach (ModuleRegistry::definitions() as $key => $definition) {
            CompanyModule::create([
                'company_id' => $company->id,
                'module_key' => $key,
                'is_enabled_for_company' => true,
            ]);
        }

        // Sync jobdomain catalog from registry
        JobdomainRegistry::sync();

        // Assign "logistique" jobdomain to the test company
        JobdomainGate::assignToCompany($company, 'logistique');

        // Sample shipments
        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $admin->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => '12 Rue de Paris, 75001 Paris',
            'destination_address' => '45 Avenue des Champs, 69001 Lyon',
            'scheduled_at' => now()->addDays(2),
            'notes' => 'Fragile goods — handle with care',
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $admin->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_PLANNED,
            'origin_address' => '8 Boulevard Haussmann, 75009 Paris',
            'destination_address' => '22 Rue de la Gare, 33000 Bordeaux',
            'scheduled_at' => now()->addDays(5),
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $member->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_IN_TRANSIT,
            'origin_address' => '3 Place Bellecour, 69002 Lyon',
            'destination_address' => '15 Quai des Belges, 13001 Marseille',
            'scheduled_at' => now()->subDay(),
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $admin->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DELIVERED,
            'origin_address' => '1 Rue du Port, 44000 Nantes',
            'destination_address' => '10 Allée du Commerce, 31000 Toulouse',
            'scheduled_at' => now()->subDays(3),
        ]);
    }
}
