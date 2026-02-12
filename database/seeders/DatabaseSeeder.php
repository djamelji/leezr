<?php

namespace Database\Seeders;

use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ─── Platform RBAC (LOT 6B) ──────────────────────────
        $this->call(PlatformSeeder::class);

        // ─── Platform scope ───────────────────────────────────
        // Platform admin — in platform_users, NO company membership
        $platformUser = PlatformUser::create([
            'name' => 'Platform Admin',
            'email' => 'platform@leezr.test',
            'password' => 'password',
        ]);

        // Attach admin role (modules only) to test platform user
        $adminRole = PlatformRole::where('key', 'admin')->first();
        if ($adminRole) {
            $platformUser->roles()->attach($adminRole->id);
        }

        // ─── Company scope ────────────────────────────────────
        // Company owner (scope Company only)
        $owner = User::create([
            'name' => 'Djamel',
            'email' => 'owner@leezr.test',
            'password' => 'password',
        ]);

        $company = Company::create([
            'name' => 'Leezr Logistics',
            'slug' => 'leezr-logistics',
        ]);

        $company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        // Admin member
        $admin = User::create([
            'name' => 'Alice Martin',
            'email' => 'admin@leezr.test',
            'password' => 'password',
        ]);

        $company->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'admin',
        ]);

        // Simple user
        $user = User::create([
            'name' => 'Bob Dupont',
            'email' => 'bob@leezr.test',
            'password' => 'password',
        ]);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
        ]);

        // ─── Module & Jobdomain setup ─────────────────────────
        ModuleRegistry::sync();

        foreach (ModuleRegistry::definitions() as $key => $definition) {
            CompanyModule::create([
                'company_id' => $company->id,
                'module_key' => $key,
                'is_enabled_for_company' => true,
            ]);
        }

        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($company, 'logistique');

        // ─── Sample shipments ─────────────────────────────────
        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $owner->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => '12 Rue de Paris, 75001 Paris',
            'destination_address' => '45 Avenue des Champs, 69001 Lyon',
            'scheduled_at' => now()->addDays(2),
            'notes' => 'Fragile goods — handle with care',
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $owner->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_PLANNED,
            'origin_address' => '8 Boulevard Haussmann, 75009 Paris',
            'destination_address' => '22 Rue de la Gare, 33000 Bordeaux',
            'scheduled_at' => now()->addDays(5),
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $admin->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_IN_TRANSIT,
            'origin_address' => '3 Place Bellecour, 69002 Lyon',
            'destination_address' => '15 Quai des Belges, 13001 Marseille',
            'scheduled_at' => now()->subDay(),
        ]);

        Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $owner->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DELIVERED,
            'origin_address' => '1 Rue du Port, 44000 Nantes',
            'destination_address' => '10 Allée du Commerce, 31000 Toulouse',
            'scheduled_at' => now()->subDays(3),
        ]);
    }
}
